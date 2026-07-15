<?php
// Cache.php

class Cache {
    private $cacheDir;

    public function __construct($cacheDir = __DIR__ . '/cache') {
        $this->cacheDir = $cacheDir;
        if (!file_exists($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    private function getFilePath($key) {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }

    public function get($key) {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);

        // لو انتهت مدة الصلاحية
        if ($data['expires_at'] < time()) {
            unlink($file);
            return null;
        }

        return $data['value'];
    }

    public function set($key, $value, $ttl = 300) { // ttl بالثواني (default: 5 دقائق)
        $file = $this->getFilePath($key);

        $data = [
            'value' => $value,
            'expires_at' => time() + $ttl
        ];

        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    public function delete($key) {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    public function clear() {
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
    }

    // مفيد جداً: لو مفيش cache ينفذ الـ callback ويخزن نتيجته
    public function remember($key, $ttl, callable $callback) {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }
}