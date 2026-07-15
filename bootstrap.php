<?php
// bootstrap.php

use App\Core\Container;
use App\Core\Logger;
use App\Database\Database;

use App\Contracts\StudentRepositoryInterface;
use App\Repositories\StudentRepository;

use App\Contracts\UserRepositoryInterface;
use App\Repositories\UserRepository;

use App\Services\StudentService;
use App\Services\UserService;
use App\Services\AuthService;

use App\Controllers\StudentController;
use App\Controllers\AuthController;

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Contracts\LectureRepositoryInterface;
use App\Repositories\LectureRepository;
use App\Services\LectureService;
use App\Controllers\LectureController;
$container = new Container();

// --- Core ---
$container->singleton(Database::class, function () {
    return new Database(
        "mysql:host=localhost;
        dbname=nagy education;charset=utf8mb4",
        "root",
        ""
    );
});

$container->singleton(Logger::class, function () {
    return new Logger();
});

// --- Repositories ---
$container->bind(StudentRepositoryInterface::class, function ($c) {
    return new StudentRepository($c->make(Database::class));
});

$container->bind(UserRepositoryInterface::class, function ($c) {
    return new UserRepository($c->make(Database::class));
});

// --- Services ---
$container->bind(UserService::class, function ($c) {
    return new UserService(
        $c->make(UserRepositoryInterface::class),
        $c->make(Logger::class)
    );
});

$container->bind(AuthService::class, function ($c) {
    return new AuthService(
        $c->make(UserRepositoryInterface::class),
        $c->make(Logger::class)
    );
});

$container->bind(StudentService::class, function ($c) {
    return new StudentService(
        $c->make(StudentRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(UserService::class),
        $c->make(AuthService::class),
        $c->make(Database::class),
        $c->make(Logger::class)
    );
});

// --- Controllers ---
$container->bind(StudentController::class, function ($c) {
    return new StudentController($c->make
    (StudentService::class));
});

$container->bind(AuthController::class, function ($c) {
    return new AuthController(
        $c->make(AuthService::class),
        $c->make(UserService::class),
        $c->make(StudentService::class)
    );
}); 

// --- Middleware ---
$container->bind(AuthMiddleware::class, fn($c) => new AuthMiddleware());
$container->bind(AdminMiddleware::class, fn($c) => new AdminMiddleware());
$container->bind(LectureRepositoryInterface::class, function ($c) {
    return new LectureRepository($c->make(Database::class));
});

$container->bind(LectureService::class, function ($c) {
    return new LectureService( 
        $c->make(LectureRepositoryInterface::class),
        $c->make(StudentRepositoryInterface::class),
        $c->make(Database::class),
        $c->make(Logger::class)
    );
});

$container->bind(LectureController::class, function ($c) {
    return new LectureController($c->make(LectureService::class));
});
return $container;