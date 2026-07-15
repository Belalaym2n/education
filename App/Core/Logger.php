<?php
// Logger.php
namespace App\Core;

use Throwable;
class Logger { 
    private $logDir;

    public function __construct($logDir = __DIR__ . '/logs') {
        $this->logDir = $logDir;
        if (!file_exists($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        } 
    }

    private function write($level, $message, $context = []) {
        $date = date('Y-m-d H:i:s');
        $file = $this->logDir . '/' . date('Y-m-d') . '.log';

        $contextStr = !empty($context) ? json_encode($context) : '';
        $line = "[$date] [$level] $message $contextStr" . PHP_EOL;

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    public function info($message, $context = []) {
        $this->write('INFO', $message, $context);
    }

    public function warning($message, $context = []) {
        $this->write('WARNING', $message, $context);
    }

    public function error($message, $context = []) {
        $this->write('ERROR', $message, $context);
    }

    public function critical($message, $context = []) {
        $this->write('CRITICAL', $message, $context);
    }

    public function logException(Throwable $e) {
        $this->error($e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}