<?php
namespace App\config;

/**
 * Firebase Cloud Messaging (HTTP v1) configuration.
 * All secrets come from environment variables — never hardcoded.
 */
return [
    'project_id' => getenv('FIREBASE_PROJECT_ID') ?: '',
    'credentials_path' => getenv('FIREBASE_CREDENTIALS_PATH') ?: __DIR__ . '/../storage/firebase/service-account.json',
    'fcm_endpoint' => 'https://fcm.googleapis.com/v1/projects/%s/messages:send',
    'oauth_scope' => 'https://www.googleapis.com/auth/firebase.messaging',
    'token_url' => 'https://oauth2.googleapis.com/token',
    'http_timeout_seconds' => (int) (getenv('FIREBASE_HTTP_TIMEOUT') ?: 10),
    'max_retry_attempts' => (int) (getenv('FIREBASE_MAX_RETRIES') ?: 3),
    'retry_backoff_ms' => (int) (getenv('FIREBASE_RETRY_BACKOFF_MS') ?: 500),
    'multicast_chunk_size' => (int) (getenv('FIREBASE_CHUNK_SIZE') ?: 500), // FCM v1 hard limit per send call is 1 (v1 has no true multicast — we fan out; batching is for our own throughput control)
]; 