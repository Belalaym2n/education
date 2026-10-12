<?php
// index.php

require __DIR__ . "/vendor/autoload.php";

use App\Core\EnvLoader;
use App\Core\Config;

use App\Core\ErrorHandler;
use App\Middleware\AuthMiddleware;
 use App\Services\DeviceBindingService;
EnvLoader::load(__DIR__ . '/.env');

Config::init();

$container = require __DIR__ . "/bootstrap.php";


ErrorHandler::init(Config::get('app.debug'));

header('Content-Type: application/json');
  
 
$origin = Config::get('app.env') === 'production'
    ? 'https://yourdomain.com'
    : '*'; 

header("Access-Control-Allow-Origin: $origin");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Device-ID");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$container->bind(AuthMiddleware::class, function ($c) {
    return new AuthMiddleware(
        $c->make(DeviceBindingService::class)
    );
});
require __DIR__ . '/App/Routing/routes.php';

 $method = $_SERVER['REQUEST_METHOD'];

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$prefix = '/education/index.php';

if (str_starts_with($uri, $prefix)) {
    $uri = substr($uri, strlen($prefix));
}

// Make sure the API route is correctly formatted
$uri = '/' . ltrim($uri, '/');

// If api and auth got concatenated, fix it
$uri = preg_replace(
    '#^/api(?!/)#',
    '/api/',
    $uri
);

error_log('REQUEST URI: ' . $_SERVER['REQUEST_URI']);
error_log('FINAL URI: ' . $uri);

$result = $router->dispatch($method, $uri);

echo json_encode($result);