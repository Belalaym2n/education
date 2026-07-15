<?php
// RateLimitMiddleware.php

namespace App\Core;
use App\Exceptions\RateLimitException;
 
use App\Core\RateLimiter;

 
class RateLimitMiddleware
{
    public static function handle()
    {
$limiter = new RateLimiter(1, 60); 
        $identifier = $_SERVER['REMOTE_ADDR'];

        if (isset($_SESSION['user_id'])) {
            $identifier = 'user_' . $_SESSION['user_id'];
        }

        $result = $limiter->check($identifier);

        header('X-RateLimit-Remaining: ' . $result['remaining']);

    if (!$result['allowed']) {
 
    throw new RateLimitException( );
}
    }
}

?>