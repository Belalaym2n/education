<?php

namespace App\Routing;

use App\Controllers\CouponController;
use App\Controllers\StudentController;
use App\Controllers\AuthController;
use App\Routing\Router;
use App\Controllers\LectureController;
use App\Controllers\StageController;
use App\Controllers\NotificationController;
use App\Controllers\FlutterNotificationController;
use App\Controllers\DeviceController;
use App\Controllers\StudentResultController;
use App\Controllers\QuizController;
use App\Controllers\AttendanceController; // NEW
 
use App\Controllers\AttemptController;
use App\Controllers\ResultsController;
require_once __DIR__ . '/game_exam_routes.php';
$router = new Router($container);

$router->group(['prefix' => '/api'], function (Router $router) {

    // --- Auth ---
    $router->post('/auth/login', [AuthController::class, 'login']);
    $router->post('/auth/logout', [AuthController::class, 'logout'], ['auth']);
    $router->get(
        '/auth/me',
        [AuthController::class, 'me'],
        ['auth']
    );

    // --- Student profile photo ---
    // Upload (multipart field "image"); the student is the token's owner.
    $router->post('/students/me/profile-image', [\App\Controllers\ProfileImageController::class, 'upload'], ['auth']);
    // Serve a stored photo (random, unlisted names; see ProfileImageService).
    $router->get('/media/profile-images/{file}', [\App\Controllers\ProfileImageController::class, 'show']);

    // --- Registration ---
    $router->post(
        '/students/register',
        [AuthController::class, 'registerStudent']
    );
    $router->post('/teachers/register', [AuthController::class, 'registerTeacher'], ['auth', 'admin']);
    $router->post('/admins/register', [AuthController::class, 'registerAdmin'], ['auth', 'admin']);

    // --- Students (CRUD - بيانات الطالب مش الـ Auth) ---
    $router->get(
        '/students',
        [StudentController::class, 'index'],
        ['auth', 'admin']
    );
    $router->get('/students/{id}', [StudentController::class, 'show'], ['auth']);
    $router->put('/students/{id}', [StudentController::class, 'update'], ['auth']);
    $router->delete('/students/{id}', [StudentController::class, 'destroy'], ['auth']);
$router->post('/students/{id}/reset-device', [StudentController::class, 'resetDevice'], ['auth', 'admin']);
  
    // --- Lectures ---
    $router->get('/lectures', [LectureController::class, 'index'], ['auth']); 

    $router->get('/lectures/stage', [LectureController::class, 'getByStage'], ['auth']);
    // Admin-only: returns unpublished lectures too (used by the dashboard's
    // coupon screen). Was reachable by any authenticated student.
    $router->get('/lectures/stage/{id}', [LectureController::class, 'getByStageForAdmin'], ['auth', 'admin']);

    $router->get('/lectures/{id}', [LectureController::class, 'show'], ['auth']);

    $router->post('/lectures', [LectureController::class, 'store'], ['auth', 'admin']);
    $router->put('/lectures/{id}', [LectureController::class, 'update'], ['auth', 'admin']);
    $router->delete('/lectures/{id}', [LectureController::class, 'destroy'], ['auth', 'admin']);

    $router->patch('/lectures/{id}/publish', [LectureController::class, 'publish'], ['auth', 'admin']);
    $router->patch('/lectures/{id}/unpublish', [LectureController::class, 'unpublish'], ['auth', 'admin']);
    $router->patch('/lectures/reorder', [LectureController::class, 'reorder'], ['auth', 'admin']);

    registerGameExamRoutes($router);

    $router->get('/stages', [StageController::class, 'index']);
    $router->get('/years', [StageController::class, 'getYearsAcademy']);


    // --- Coupons (Admin) ---
    $router->get('/coupons', [CouponController::class, 'index'], ['auth', 'admin']);

    $router->get('/coupons/summary', [CouponController::class, 'summary'], ['auth', 'admin']);
    $router->get('/coupons/export', [CouponController::class, 'export'], ['auth', 'admin']);

    $router->post('/coupons/bulk', [CouponController::class, 'bulkStore'], ['auth', 'admin']);

    $router->get('/coupons/{id}', [CouponController::class, 'show'], ['auth', 'admin']);
    $router->put('/coupons/{id}', [CouponController::class, 'update'], ['auth', 'admin']);
    $router->delete('/coupons/{id}', [CouponController::class, 'destroy'], ['auth', 'admin']);
    $router->post('/coupons/{id}/restore', [CouponController::class, 'restore'], ['auth', 'admin']);

    $router->post('/coupons', [CouponController::class, 'store'], ['auth', 'admin']);
    // --- Coupons (Student) ---
    $router->post('/coupons-student/redeem', [CouponController::class, 'redeem'], ['auth']);
    $router->get('/my-lectures', [CouponController::class, 'myLectures'], ['auth']);
    $router->get( '/my-lectures/{lecture_id}/check', [CouponController::class, 'checkLectureAccess'], ['auth'] );
    // --- Teacher dashboard analytics (aggregates only, admin role) ---
    $router->get('/admin/analytics/overview', [\App\Controllers\DashboardAnalyticsController::class, 'overview'], ['auth', 'admin']);
    $router->get('/admin/analytics/activity', [\App\Controllers\DashboardAnalyticsController::class, 'activity'], ['auth', 'admin']);

    $router->group(['prefix' => '/admin/notifications', 'middleware' => ['auth', 'admin']], function (Router $router) {
        $router->get('/', [NotificationController::class, 'index']);
        $router->get('/{id}', [NotificationController::class, 'show']);
        $router->post('/', [NotificationController::class, 'store']);
        $router->put('/{id}', [NotificationController::class, 'update']);
        $router->delete('/{id}', [NotificationController::class, 'destroy']);
        $router->patch('/{id}/restore', [NotificationController::class, 'restore']);
        $router->patch('/{id}/enable', [NotificationController::class, 'enable']);
        $router->patch('/{id}/disable', [NotificationController::class, 'disable']);
    });


    $router->get('/stages/quizzes', [QuizController::class, 'getForStage'], ['auth']);
    $router->get('/quizzes/insights', [QuizController::class, 'insights'], ['auth', 'admin']);  // done  
    $router->get('/quizzes/{id}/builder', [QuizController::class, 'showWithQuestions'], ['auth', 'admin']); // done 
    $router->get('/quizzes/{id}', [QuizController::class, 'show'], ['auth']);  // done
    $router->get('/quizzes', [QuizController::class, 'index'], ['auth', 'admin']); // done

    // --- Quizzes: writes (teacher/admin only) ---
    $router->post('/quizzes', [QuizController::class, 'store'],  ['auth', 'admin']); // done
    $router->put('/quizzes/{id}', [QuizController::class, 'update'], ['auth', 'admin']); // done 

    $router->put('/quizzes/{id}/questions', [QuizController::class, 'saveQuestions'], ['auth', 'admin']); // done 
    $router->patch('/quizzes/{id}/publish', [QuizController::class, 'publish'], ['auth', 'admin']);
    $router->patch('/quizzes/{id}/unpublish', [QuizController::class, 'unpublish'], ['auth', 'admin']);
    $router->patch('/quizzes/{id}/archive', [QuizController::class, 'archive'], ['auth', 'admin']);
    $router->patch('/quizzes/{id}/visibility', [QuizController::class, 'toggleVisibility'], ['auth', 'admin']);
    $router->post('/quizzes/{id}/duplicate', [QuizController::class, 'duplicate'], ['auth', 'admin']);
    $router->delete('/quizzes/{id}', [QuizController::class, 'destroy'], ['auth', 'admin']);




    // --- Student attempt flow (auth required, but students can start/submit their own attempts) ---
    // --- Student: latest quiz result ---
$router->get(
    '/quizzes/{quizId}/my-result',
    [AttemptController::class, 'getMyLatestResult'],
    ['auth']
);
    $router->post('/quizzes/{quizId}/attempts', [AttemptController::class, 'start'], ['auth',]); // done 
    $router->get('/attempts/{attemptId}', [AttemptController::class, 'show'], ['auth']);
    $router->put('/attempts/{attemptId}/answers/{questionId}', [AttemptController::class, 'saveAnswer'], ['auth']); // done  
    $router->post('/attempts/{attemptId}/submit', [AttemptController::class, 'submit'], ['auth']); // done  
    $router->post('/attempts/{attemptId}/auto-submit', [AttemptController::class, 'autoSubmit'], ['auth']); // done  
    $router->get('/attempts/{attemptId}/results', [AttemptController::class, 'getResults'], ['auth']);

    // --- Teacher: Results Dashboard & essay review (teacher/admin only) ---
    $router->get('/quizzes/{quizId}/results/insights', [ResultsController::class, 'insights'],  ['auth', 'admin']);  // done 
    $router->get('/quizzes/{quizId}/results', [ResultsController::class, 'index'], ['auth', 'admin']);// done 
    $router->get('/quizzes/{quizId}/results/export', [ResultsController::class, 'export'], ['auth', 'admin']);
    $router->get('/attempts/{attemptId}/submission-detail', [ResultsController::class, 'submissionDetail'], ['auth', 'admin']);

    // --- Teacher: Essay review workflow ---
    $router->get('/quizzes/{quizId}/essays/pending', [ResultsController::class, 'pendingEssays'], ['auth', 'role:admin,teacher']);
    $router->get('/attempts/{attemptId}/essays', [ResultsController::class, 'attemptEssays'], ['auth', 'role:admin,teacher']);
    $router->put('/answers/{answerId}/score', [ResultsController::class, 'scoreEssay'], ['auth', 'role:admin,teacher']);
    $router->patch('/answers/{answerId}/mark-reviewed', [ResultsController::class, 'markEssayReviewed'], ['auth', 'role:admin,teacher']);
    $router->post('/quizzes/{quizId}/essays/publish-reviews', [ResultsController::class, 'publishReviews'], ['auth', 'role:admin,teacher']);
});



// --- Quizzes: reads (open to any authenticated teacher/admin) ---




/** @var Router $router */
$router->group(['prefix' => '/api'], function (Router $router) {


    $router->group(['prefix' => '/attendance', 'middleware' => ['auth']], function (Router $router) {
        $router->post('/scan', [AttendanceController::class, 'scan']);
        $router->post('/bulk', [AttendanceController::class, 'bulk']);
       $router->get('/records', [AttendanceController::class, 'index']);
    $router->get('/statistics', [AttendanceController::class, 'statistics']);
    $router->post('/{id}/notify', [AttendanceController::class, 'notifyParent']);
    
   }); 


    // --- Admin dashboard: Notification management ---

    // --- Flutter app: read/unread flows (any authenticated student) ---
    $router->group(['prefix' => '/notifications', 'middleware' => ['auth']], function (Router $router) {
        $router->get('/', [FlutterNotificationController::class, 'index']);
        $router->get('/unread-count', [FlutterNotificationController::class, 'unreadCount']);
        $router->get('/{notificationId}', [FlutterNotificationController::class, 'show']);
        $router->patch('/{notificationId}/read', [FlutterNotificationController::class, 'markAsRead']);
        $router->patch('/read-all', [FlutterNotificationController::class, 'markAllAsRead']);
        $router->delete('/{notificationId}/local', [FlutterNotificationController::class, 'destroyLocal']);
    });

    // --- Device registration (FCM tokens) ---
    $router->group(['prefix' => '/devices', 'middleware' => ['auth']], function (Router $router) {
        $router->post('/register', [DeviceController::class, 'register']);
        $router->post('/unregister', [DeviceController::class, 'unregister']);
        $router->get('/', [DeviceController::class, 'index']);
    });


 
    // --- Student Results (End of Year Marks) ---

    // Students/parents view results (read-only), teachers/admins manage them.
    $router->get('/student-results', [StudentResultController::class, 'index'], ['auth']);
    $router->get('/student-results/{id}', [StudentResultController::class, 'show'], ['auth']);

    $router->post('/student-results', [StudentResultController::class, 'store'], ['auth', 'admin']);
    $router->put('/student-results/{id}', [StudentResultController::class, 'update'], ['auth', 'admin']);
    $router->delete('/student-results/{id}', [StudentResultController::class, 'destroy'], ['auth', 'admin']);
});