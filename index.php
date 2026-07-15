<?php
// index.php


require __DIR__ . "/vendor/autoload.php";
$container = require __DIR__ . "/bootstrap.php";
 use App\Middleware\AdminMiddleware;

use App\Controllers\StudentController;
use App\Core\ErrorHandler;
use App\Middleware\AuthMiddleware; 
use App\Database\Database;
ErrorHandler::init(true);  
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: ana *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json" );
  
 
  // تسجيل الـ Middleware في الـ Container
$container->bind(AuthMiddleware::class, fn($c) => new AuthMiddleware());

$container->bind(AdminMiddleware::class, function ($c) {
    return new AdminMiddleware(
        $c->make(Database::class)
    );
});
 
  
// --- بناء الـ Router وتحميل الـ Routes -d--
 require __DIR__ . '/App/Routing/routes.php';

// --- تنفيذ الطلب ---
$result = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
echo json_encode($result);   