<?php

namespace App\Services;

use App\Core\Logger;
use App\Exceptions\AppException;

/**
 * Firebase Cloud Messaging integration using HTTP v1 (never the legacy API).
 * Handles:
 *  - Service-account OAuth2 access-token generation (with in-process caching)
 *  - Sending a single message per device token (v1 has no server-side multicast;
 *    we fan out ourselves in controlled chunks)
 *  - Retry with exponential backoff for transient failures
 *  - Detection of invalid/unregistered tokens so callers can deactivate them
 */
final class FirebaseService
{
    /**
     * Setup problems that no retry can fix. Sending stops on the first one
     * instead of retrying every token with backoff.
     */
    private const PERMANENT_ERROR_CODES = [
        'FIREBASE_MISCONFIGURED',
        'FIREBASE_CREDENTIALS_MISSING',
        'FIREBASE_CREDENTIALS_INVALID',
        'FIREBASE_JWT_SIGNING_FAILED',
    ];

    private ?string $cachedAccessToken = null;
    private int $cachedAccessTokenExpiresAt = 0;

    /** @var array<string, mixed> */
    private Logger $logger;
    private array $config; 

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->config = require dirname(__DIR__, 2) . '/App/config/firebase.php';
    }  
    /** 
     * Send a single push message to one device token.
     *
     * @return array{success: bool, error_code: ?string, error_message: ?string, invalid_token: bool}
     */
    public function sendToToken(string $token, string $title, string $body, array $data = []): array
    {
        $attempt = 0;
        $maxAttempts = (int) $this->config['max_retry_attempts'];

        while (true) {
            $attempt++;

            try {
                $this->assertConfigured();
                $accessToken = $this->getAccessToken();
                $result = $this->doSend($accessToken, $token, $title, $body, $data);

                return $result;
            } catch (\Throwable $e) {
                $this->logger->error('FCM send failed', [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($e instanceof AppException && in_array($e->getErrorCode(), self::PERMANENT_ERROR_CODES, true)) {
                    return [
                        'success' => false,
                        'error_code' => $e->getErrorCode(),
                        'error_message' => $e->getMessage(),
                        'invalid_token' => false,
                    ];
                }

                if ($attempt >= $maxAttempts) {
                    return [
                        'success' => false,
                        'error_code' => 'MAX_RETRIES_EXCEEDED',
                        'error_message' => $e->getMessage(),
                        'invalid_token' => false,
                    ];
                }

                usleep((int) $this->config['retry_backoff_ms'] * 1000 * $attempt);
            }
        }
    }

    /**
     * Fan out a message to many tokens, chunked to control throughput/memory.
     *
     * @param string[] $tokens
     * @return array<int, array{token: string, success: bool, invalid_token: bool, error_message: ?string}>
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $results = [];
        $chunkSize = (int) $this->config['multicast_chunk_size'];

        $permanentFailure = null;

        foreach (array_chunk($tokens, $chunkSize) as $chunk) {
            foreach ($chunk as $token) {
                $sendResult = $permanentFailure ?? $this->sendToToken($token, $title, $body, $data);

                if (in_array($sendResult['error_code'], self::PERMANENT_ERROR_CODES, true)) {
                    $permanentFailure = $sendResult;
                }

                $results[] = array_merge(['token' => $token], $sendResult);
            }
        }

        return $results;
    }

    /**
     * Fails before any network call when the project id is missing, so a
     * misconfigured server does not burn an OAuth round-trip per token.
     */
    private function assertConfigured(): void
    {
        if (empty($this->config['project_id'])) {
            throw new AppException('Firebase project_id is not configured', 500, 'FIREBASE_MISCONFIGURED');
        }
    }

    /**
     * Sends the actual HTTP v1 request. Isolated for testability.
     */
    private function doSend(string $accessToken, string $token, string $title, string $body, array $data): array
    {
        $projectId = $this->config['project_id'];

        if (empty($projectId)) {
            throw new AppException('Firebase project_id is not configured', 500, 'FIREBASE_MISCONFIGURED');
        }

        $url = sprintf($this->config['fcm_endpoint'], $projectId);

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => array_map('strval', $data),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        // Must match the channel the app creates at startup.
                        'channel_id' => $this->config['android_channel_id'],
                        'sound' => 'default',
                    ],
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json; charset=UTF-8',
            ],
            CURLOPT_TIMEOUT => (int) $this->config['http_timeout_seconds'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new AppException("FCM network error: $curlError", 502, 'FIREBASE_NETWORK_ERROR');
        }

        $decoded = json_decode($response, true) ?? [];

        if ($httpCode === 200) {
            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'invalid_token' => false,
            ];
        }

        $errorStatus = $decoded['error']['status'] ?? 'UNKNOWN';
        $errorMessage = $decoded['error']['message'] ?? "HTTP $httpCode";
        // Only errors that prove the token itself is dead. INVALID_ARGUMENT
        // also covers payload bugs, so it counts only when it names the
        // token; SENDER_ID_MISMATCH means wrong server credentials, not a
        // bad token, and must never deactivate devices.
        $isInvalidToken = in_array($errorStatus, ['NOT_FOUND', 'UNREGISTERED'], true)
            || ($errorStatus === 'INVALID_ARGUMENT' && stripos($errorMessage, 'registration token') !== false);

        if ($httpCode === 429 || $httpCode >= 500) {
            // Transient: let caller's retry loop handle it.
            throw new AppException("FCM transient error ($errorStatus): $errorMessage", $httpCode, 'FIREBASE_TRANSIENT_ERROR');
        }

        return [
            'success' => false,
            'error_code' => $errorStatus,
            'error_message' => $errorMessage,
            'invalid_token' => $isInvalidToken,
        ];
    }

    /**
     * Generates (and caches for the remaining lifetime) an OAuth2 access token
     * from the service-account credentials, per Google's server-to-server flow.
     */
    private function getAccessToken(): string
    {
        if ($this->cachedAccessToken !== null && time() < $this->cachedAccessTokenExpiresAt - 60) {
            return $this->cachedAccessToken;
        }

        $credentialsPath = $this->config['credentials_path'];

        if (!is_readable($credentialsPath)) {
            throw new AppException('Firebase service account file not found or unreadable', 500, 'FIREBASE_CREDENTIALS_MISSING');
        }

        $credentials = json_decode((string) file_get_contents($credentialsPath), true);

        if (!is_array($credentials) || empty($credentials['private_key']) || empty($credentials['client_email'])) {
            throw new AppException('Invalid Firebase service account file', 500, 'FIREBASE_CREDENTIALS_INVALID');
        }

        $now = time();
        $expiry = $now + 3600;

        $jwtHeader = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $jwtClaims = $this->base64UrlEncode(json_encode([
            'iss' => $credentials['client_email'],
            'scope' => $this->config['oauth_scope'],
            'aud' => $this->config['token_url'],
            'iat' => $now,
            'exp' => $expiry,
        ]));

        $signatureInput = "$jwtHeader.$jwtClaims";
        $signature = '';

        $signed = openssl_sign($signatureInput, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256);

        if (!$signed) {
            throw new AppException('Failed to sign Firebase JWT', 500, 'FIREBASE_JWT_SIGNING_FAILED');
        }

        $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);

        $ch = curl_init($this->config['token_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => (int) $this->config['http_timeout_seconds'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new AppException('Failed to obtain Firebase access token', 502, 'FIREBASE_AUTH_FAILED');
        }

        $decoded = json_decode($response, true);

        if (empty($decoded['access_token'])) {
            throw new AppException('Firebase token response missing access_token', 502, 'FIREBASE_AUTH_FAILED');
        }

        $this->cachedAccessToken = $decoded['access_token'];
        $this->cachedAccessTokenExpiresAt = $now + (int) ($decoded['expires_in'] ?? 3600);

        return $this->cachedAccessToken;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
