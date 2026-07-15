<?php
// RateLimiter.php
 namespace App\Core;

class RateLimiter {
    private $storageDir;
    private $maxRequests;
    private $timeWindow; // بالثواني

    public function __construct($maxRequests = 60, $timeWindow = 60, $storageDir = __DIR__ . '/rate_limits') {
        $this->maxRequests = $maxRequests;
        $this->timeWindow = $timeWindow;
        $this->storageDir = $storageDir;
 
        if (!file_exists($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    public function check($identifier) {
        $file = $this->storageDir . '/' . md5($identifier) . '.json';
        $now = time();


        // foler for api 

        // 
        $data = ['requests' => []];
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
        }

        // امسح الطلبات القديمة اللي خارج الـ time window
        $data['requests'] = array_filter($data['requests'], function($timestamp) use ($now) {
            return ($now - $timestamp) < $this->timeWindow;
        });//70

        $currentCount = count($data['requests']);

        if ($currentCount >= $this->maxRequests) {
            $oldestRequest = min($data['requests']);

            // 60  ( 80 -10)
            $retryAfter = $this->timeWindow - ($now - $oldestRequest);

            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => $retryAfter
            ];
        }

         $data['requests'][] = $now;
        file_put_contents($file, json_encode($data), LOCK_EX);

        return [
            'allowed' => true,
            'remaining' => $this->maxRequests - $currentCount - 1,
            'retry_after' => 0
        ];
    }
}