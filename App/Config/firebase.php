<?php

namespace App\Config;

return [
    'project_id' => getenv('FIREBASE_PROJECT_ID') ?: '',

    'credentials_path' => getenv('FIREBASE_CREDENTIALS_PATH')
        ?: dirname(__DIR__, 2) . '/Storage/Firebase/service-account.json',

    'fcm_endpoint' => 'https://fcm.googleapis.com/v1/projects/%s/messages:send',

    'oauth_scope' => 'https://www.googleapis.com/auth/firebase.messaging',

    'token_url' => 'https://oauth2.googleapis.com/token',

    'http_timeout_seconds' => (int) (getenv('FIREBASE_HTTP_TIMEOUT') ?: 10),

    'max_retry_attempts' => (int) (getenv('FIREBASE_MAX_RETRIES') ?: 3),

    'retry_backoff_ms' => (int) (getenv('FIREBASE_RETRY_BACKOFF_MS') ?: 500),

    'multicast_chunk_size' => (int) (getenv('FIREBASE_CHUNK_SIZE') ?: 500),

    // Must match the channel id created by the Flutter app. Android keeps a
    // channel's sound/importance once created, so changing them means a new id.
    'android_channel_id' => 'edu_notifications_v1',
];