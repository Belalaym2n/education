<?php

namespace App\Routing;

use App\Controllers\StudentController;
use App\Controllers\AuthController;
use App\Routing\Router;
use App\Controllers\LectureController;
$router = new Router($container);

$router->group(['prefix' => '/api'], function (Router $router) {

    // --- Auth ---
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/logout', [AuthController::class, 'logout'], ['auth']);
    $router->get('/auth/me',
     [AuthController::class, 'me'], ['auth']);

    // --- Registration ---
    $router->post('/students/register',
     [AuthController::class, 'registerStudent']);
    $router->post('/teachers/register', [AuthController::class, 'registerTeacher'], ['auth', 'admin']);
    $router->post('/admins/register', [AuthController::class, 'registerAdmin'], ['auth', 'admin']);
 
    // --- Students (CRUD - بيانات الطالب مش الـ Auth) ---
    $router->get('/students', 
    [StudentController::class, 'index'],
     ['auth', 'admin']);
    $router->get('/students/{id}', [StudentController::class, 'show'], ['auth']);
    $router->put('/students/{id}', [StudentController::class, 'update'], ['auth']);
    $router->delete('/students/{id}', [StudentController::class, 'destroy'], ['auth', 'admin']);

 
    // --- Lectures ---
    $router->get('/lectures', [LectureController::class, 'index'], ['auth']);
    $router->get('/lectures/{id}', [LectureController::class, 'show'], ['auth']);

    $router->get('/lectures/stage/{stage_id}', [LectureController::class, 'findByStageId'], ['auth']);
 
    
    $router->post('/lectures', [LectureController::class, 'store'], ['auth', 'admin']);
    $router->put('/lectures/{id}', [LectureController::class, 'update'], ['auth', 'admin']);
    $router->delete('/lectures/{id}', [LectureController::class, 'destroy'], ['auth', 'admin']);
 
    $router->patch('/lectures/{id}/publish', [LectureController::class, 'publish'], ['auth', 'admin']);
    $router->patch('/lectures/{id}/unpublish', [LectureController::class, 'unpublish'], ['auth', 'admin']);
    $router->patch('/lectures/reorder', [LectureController::class, 'reorder'], ['auth', 'admin']);
    // مثال لحماية Lectures (لو الموديول ده موجود عندك بالفعل)
    // $router->post('/lectures', [LectureController::class, 'store'], ['auth', 'admin']);
    // $router->put('/lectures/{id}', [LectureController::class, 'update'], ['auth', 'admin']);
    // $router->delete('/lectures/{id}', [LectureController::class, 'destroy'], ['auth', 'admin']);
    // $router->patch('/lectures/{id}/lock', [LectureController::class, 'lock'], ['auth', 'admin']);
    // $router->patch('/lectures/{id}/unlock', [LectureController::class, 'unlock'], ['auth', 'admin']);
}); 