<?php
// ErrorHandler.php
 namespace App\Core;
use Throwable;
use App\Exceptions\AppException;
use App\Exceptions\ValidationException;
use ErrorException;
 class ErrorHandler {
    private static $logger;
    private static $debugMode = false; // false في production

    public static function init($debugMode = false) {
        self::$logger = new Logger();
        self::$debugMode = $debugMode;

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
    }

    // يمسك أي Exception ماحدش عمله catch
    public static function handleException(Throwable $e) {

    
        self::$logger->logException($e);

        $statusCode = 500;
        $errorCode = 'INTERNAL_ERROR';
        $message = "Something went wrong";

     if ($e instanceof AppException) {
    $statusCode = $e->getStatusCode();
    $errorCode = $e->getErrorCode();
    $message = $e->getMessage();
} 

        http_response_code($statusCode);
        header('Content-Type: application/json');

        $response = [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $message
            ]
        ];

        // في development نظهر تفاصيل أكتر
     if (self::$debugMode) {
    $response['error']['type'] = get_class($e);
    $response['error']['real_message'] = $e->getMessage();
    $response['error']['file'] = $e->getFile();
    $response['error']['line'] = $e->getLine();
    $response['error']['trace'] = explode("\n", $e->getTraceAsString());
}

        // لو Validation Exception نضيف الـ errors
        if ($e instanceof ValidationException) {
            $response['error']['errors'] = $e->getErrors();
        }

        echo json_encode($response);
        exit;
    }

    // يمسك الـ PHP errors العادية (warnings, notices) ويحولها لـ Exception
    public static function handleError($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
} 