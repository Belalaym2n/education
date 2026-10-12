<?php

namespace App\Routing;

use App\Controllers\GameExamController;
use App\Controllers\StudentGameExamController;

/**
 * Gamified Exams routes. Called from routes.php INSIDE the '/api' group.
 *
 * Order matters: the Router returns the first matching pattern, so static
 * segments (statistics, top-students) are registered before '{id}'.
 */
function registerGameExamRoutes(Router $router): void
{
    // ----- Dashboard (admin) -----
    $router->group(['prefix' => '/game-exams', 'middleware' => ['auth', 'admin']], function (Router $router) {
        $router->get('/statistics', [GameExamController::class, 'statistics']);
        $router->get('/top-students', [GameExamController::class, 'topStudents']);

        $router->get('', [GameExamController::class, 'index']);
        $router->post('', [GameExamController::class, 'store']);

        $router->get('/{id}/results/statistics', [GameExamController::class, 'resultStatistics']);
        $router->get('/{id}/results/{attemptId}', [GameExamController::class, 'resultDetail']);
        $router->get('/{id}/results', [GameExamController::class, 'results']);

        $router->put('/{id}/questions/order', [GameExamController::class, 'reorder']);
        $router->post('/{id}/publish', [GameExamController::class, 'publish']);
        $router->post('/{id}/unpublish', [GameExamController::class, 'unpublish']);
        $router->post('/{id}/duplicate', [GameExamController::class, 'duplicate']);
        $router->post('/{id}/restore', [GameExamController::class, 'restore']);

        $router->get('/{id}', [GameExamController::class, 'show']);
        $router->put('/{id}', [GameExamController::class, 'update']);
        $router->delete('/{id}', [GameExamController::class, 'destroy']);
    });

    // ----- Student app -----
    $router->group(['prefix' => '/student/game-exams', 'middleware' => ['auth']], function (Router $router) {
        $router->get('', [StudentGameExamController::class, 'index']);
        $router->get('/{id}', [StudentGameExamController::class, 'show']);
        $router->post('/{id}/attempts', [StudentGameExamController::class, 'start']);
        $router->get('/{id}/attempts/{attemptId}/result', [StudentGameExamController::class, 'result']);
        $router->get('/{id}/attempts/{attemptId}', [StudentGameExamController::class, 'attempt']);
        $router->post('/{id}/attempts/{attemptId}/answers', [StudentGameExamController::class, 'answer']);
        $router->post('/{id}/attempts/{attemptId}/sync', [StudentGameExamController::class, 'sync']);
        $router->post('/{id}/attempts/{attemptId}/complete', [StudentGameExamController::class, 'complete']);
    });
}
