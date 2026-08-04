<?php
// index.php

require __DIR__ . "/vendor/autoload.php";

use App\Core\EnvLoader;
use App\Core\Config;

EnvLoader::load(__DIR__ . '/.env');

Config::init();

$container = require __DIR__ . "/bootstrap.php";

use App\Core\ErrorHandler;
use App\Middleware\AuthMiddleware;

ErrorHandler::init(Config::get('app.debug'));

header('Content-Type: application/json');
  
 
$origin = Config::get('app.env') === 'production'
    ? 'https://yourdomain.com'
    : '*';

header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$container->bind(AuthMiddleware::class, fn($c) => new AuthMiddleware());

require __DIR__ . '/App/Routing/routes.php';

$result = $router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

echo json_encode($result); 