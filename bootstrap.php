<?php
// bootstrap.php
use App\Contracts\CouponRepositoryInterface;
use App\Repositories\CouponRepository;
use App\Contracts\LectureOwnershipRepositoryInterface;
use App\Repositories\LectureOwnershipRepository;
use App\Services\CouponService;
use App\Controllers\CouponController;
use App\Core\Container;
use App\Core\Logger;
use App\Database\Database;

use App\Contracts\StudentRepositoryInterface;
use App\Repositories\StudentRepository;

use App\Contracts\UserRepositoryInterface;
 use App\Repositories\UserRepository;
 
// --- NEW: Father / guardian + attendance ---
use App\Contracts\FatherRepositoryInterface;
use App\Repositories\FatherRepository;
use App\Contracts\FatherStudentRepositoryInterface;
use App\Repositories\FatherStudentRepository;
use App\Contracts\AttendanceRepositoryInterface;
use App\Repositories\AttendanceRepository;
use App\Services\AttendanceService;
use App\Controllers\AttendanceController;

use App\Services\StudentService;
use App\Services\UserService;
use App\Services\AuthService;

use App\Controllers\StudentController;
use App\Controllers\AuthController;

use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Contracts\LectureRepositoryInterface;
use App\Repositories\LectureRepository;
use App\Repositories\StageRepository;
use App\Core\Config;

use App\Services\LectureService;
use App\Services\StageService;
use App\Contracts\StageRepositoryInterface;

use App\Controllers\LectureController;
use App\Controllers\StageController;
use App\Contracts\DeviceRepositoryInterface;
use App\Contracts\NotificationReadRepositoryInterface;
use App\Contracts\NotificationRepositoryInterface;
use App\Controllers\DeviceController;
use App\Controllers\FlutterNotificationController;
use App\Controllers\NotificationController;

use App\Repositories\DeviceRepository;
use App\Repositories\NotificationReadRepository;
use App\Repositories\NotificationRepository;
use App\Services\DeviceService;
use App\Services\FirebaseService;
use App\Services\NotificationService;
use App\Services\CouponCodeGenerator;
use App\Helpers\ImageUploadHelper;
use App\Contracts\StudentResultRepositoryInterface;
use App\Repositories\StudentResultRepository;
use App\Services\StudentResultService;
use App\Controllers\StudentResultController;

use App\Contracts\QuizRepositoryInterface;
use App\Contracts\QuizQuestionRepositoryInterface;
use App\Repositories\QuizRepository;
use App\Repositories\QuizQuestionRepository;
use App\Services\QuizService;
use App\Controllers\QuizController;

use App\Contracts\AttemptRepositoryInterface;
use App\Contracts\AttemptAnswerRepositoryInterface;
use App\Repositories\AttemptRepository;
use App\Repositories\AttemptAnswerRepository;
use App\Services\AttemptService;
use App\Services\EssayReviewService;
use App\Services\ResultsService;
use App\Controllers\AttemptController;
use App\Controllers\ResultsController;

$container = new Container();

// --- Core ---
$container->singleton(Database::class, function () {
    $driver  = Config::get('database.driver');
    $host    = Config::get('database.host');
    $port    = Config::get('database.port');
    $dbname  = Config::get('database.name');
    $charset = Config::get('database.charset');

    $dsn = "$driver:host=$host;port=$port;dbname=$dbname;charset=$charset";

    return new Database(
        $dsn,
        Config::get('database.user'),
        Config::get('database.pass')
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
$container->bind(NotificationRepositoryInterface::class, fn($c) => new NotificationRepository($c->make(Database::class)));
$container->bind(NotificationReadRepositoryInterface::class, fn($c) => new NotificationReadRepository($c->make(Database::class)));
$container->bind(DeviceRepositoryInterface::class, fn($c) => new DeviceRepository($c->make(Database::class)));

// --- NEW: Father / guardian + attendance repositories ---
$container->bind(FatherRepositoryInterface::class, fn($c) => new FatherRepository($c->make(Database::class)));
$container->bind(FatherStudentRepositoryInterface::class, fn($c) => new FatherStudentRepository($c->make(Database::class)));
$container->bind(AttendanceRepositoryInterface::class, fn($c) => new AttendanceRepository($c->make(Database::class)));

$container->bind(StageRepositoryInterface::class, function ($c) {
    return new StageRepository($c->make(Database::class));
});
$container->bind(CouponRepositoryInterface::class, function ($c) {
    return new CouponRepository($c->make(
        Database::class
    ));
});
$container->bind(LectureOwnershipRepositoryInterface::class, function ($c) {
    return new LectureOwnershipRepository(
        $c->make(Database::class)
    );
});
// --- Services ---
$container->bind(UserService::class, function ($c) {
    return new UserService(
        $c->make(UserRepositoryInterface::class),
        $c->make(Logger::class)
    );
});
$container->singleton(CouponCodeGenerator::class, function ($c) {
    return new CouponCodeGenerator(
        $c->make(CouponRepositoryInterface::class)
    );
});
$container->bind(CouponService::class, function ($c) {
    return new CouponService(
        $c->make(CouponRepositoryInterface::class),
        $c->make(LectureRepositoryInterface::class),
        $c->make(LectureOwnershipRepositoryInterface::class),
        $c->make(StudentRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(CouponCodeGenerator::class),
        $c->make(Database::class),
        $c->make(Logger::class)
    );
});
$container->bind(AuthService::class, function ($c) {
    return new AuthService(
        $c->make(UserRepositoryInterface::class),
        $c->make(Logger::class)
    );
});
$container->bind(StageService::class, function ($c) {
    return new StageService(
        $c->make(StageRepositoryInterface::class),
        $c->make(Logger::class)
    );
});

// --- MODIFIED: StudentService now also needs Father + FatherStudent repos ---
$container->bind(StudentService::class, function ($c) {
    return new StudentService(
        $c->make(StudentRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(FatherRepositoryInterface::class),
        $c->make(FatherStudentRepositoryInterface::class),
        $c->make(UserService::class),
        $c->make(AuthService::class),
        $c->make(Database::class),
        $c->make(Logger::class)
    );
});

$container->singleton(FirebaseService::class, fn($c) => new FirebaseService($c->make(Logger::class)));
$container->bind(NotificationService::class, fn($c) => new NotificationService(
    $c->make(NotificationRepositoryInterface::class),
    $c->make(NotificationReadRepositoryInterface::class),
    $c->make(DeviceRepositoryInterface::class),
    $c->make(StudentRepositoryInterface::class),

    $c->make(FirebaseService::class),
    $c->make(Database::class),
    $c->make(Logger::class),
    $c->make(StageRepositoryInterface::class),
));

$container->bind(DeviceService::class, fn($c) => new DeviceService(
    $c->make(DeviceRepositoryInterface::class),
    $c->make(Logger::class),
));

// --- NEW: Attendance service ---
$container->bind(AttendanceService::class, fn($c) => new AttendanceService(
    $c->make(AttendanceRepositoryInterface::class),
    $c->make(StudentRepositoryInterface::class),
    $c->make(FatherStudentRepositoryInterface::class),
    $c->make(NotificationService::class),
    $c->make(Database::class),
    $c->make(Logger::class),
));


// --- Controllers ---
$container->bind(CouponController::class, function ($c) {
    return new CouponController(
        $c->make(CouponService::class),
        $c->make(StudentService::class)
    );
});
$container->bind(StudentController::class, function ($c) {
    return new StudentController($c->make(StudentService::class));
});

$container->bind(AuthController::class, function ($c) {
    return new AuthController(
        $c->make(AuthService::class),
        $c->make(UserService::class),
        $c->make(StudentService::class)
    );
});

$container->bind(StageController::class, function ($c) {
    return new StageController(
        $c->make(StageService::class),

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



$container->bind(NotificationController::class, fn($c) => new NotificationController($c->make(NotificationService::class)));
$container->bind(FlutterNotificationController::class, fn($c) => new FlutterNotificationController($c->make(NotificationService::class)));
$container->bind(DeviceController::class, fn($c) => new DeviceController($c->make(DeviceService::class)));

// --- NEW: Attendance controller ---
$container->bind(
    AttendanceController::class,
    fn($c) => new AttendanceController(
        $c->make(AttendanceService::class),
        $c->make(AttendanceRepositoryInterface::class),
        $c->make(NotificationService::class),
    ) 
);
 
$container->bind(StudentResultRepositoryInterface::class, function ($c) {
    return new StudentResultRepository($c->make(Database::class));
});

$container->singleton(ImageUploadHelper::class, function () {
    return new ImageUploadHelper(
        __DIR__ . '/storage/uploads/student_results',
        '/uploads/student_results'
    );
});

$container->bind(StudentResultService::class, function ($c) {
    return new StudentResultService(
        $c->make(StudentResultRepositoryInterface::class),
        $c->make(StudentRepositoryInterface::class),
        $c->make(Database::class),
        $c->make(ImageUploadHelper::class),
        $c->make(Logger::class)
    );
});

$container->bind(StudentResultController::class, function ($c) {
    return new StudentResultController($c->make(StudentResultService::class));
});

$container->bind(QuizRepositoryInterface::class, function ($c) {
    return new QuizRepository($c->make(Database::class));
});

$container->bind(QuizQuestionRepositoryInterface::class, function ($c) {
    return new QuizQuestionRepository($c->make(Database::class));
});

$container->bind(QuizService::class, function ($c) {
    return new QuizService(
        $c->make(QuizRepositoryInterface::class),
        $c->make(StudentRepositoryInterface::class),
        $c->make(QuizQuestionRepositoryInterface::class),
        $c->make(Database::class),
        $c->make(Logger::class),
    );
});
$container->bind(QuizController::class, function ($c) {
    return new QuizController($c->make(QuizService::class));
});


// --- Repositories ---
$container->bind(AttemptRepositoryInterface::class, function ($c) {
    return new AttemptRepository($c->make(Database::class));
});

$container->bind(AttemptAnswerRepositoryInterface::class, function ($c) {
    return new AttemptAnswerRepository($c->make(Database::class));
});

// --- Services ---
$container->bind(AttemptService::class, function ($c) {
    return new AttemptService(
        $c->make(AttemptRepositoryInterface::class),
        $c->make(AttemptAnswerRepositoryInterface::class),
        $c->make(QuizRepositoryInterface::class),
        $c->make(Database::class),
        $c->make(Logger::class),
    );
});

// $container->bind(EssayReviewService::class, function ($c) {
//     return new EssayReviewService(
//         $c->make(AttemptRepositoryInterface::class),
//         $c->make(AttemptAnswerRepositoryInterface::class),
//         $c->make(Database::class),
//         $c->make(Logger::class),
//     );
// });

$container->bind(ResultsService::class, function ($c) {
    return new ResultsService(
        $c->make(AttemptRepositoryInterface::class),
        $c->make(Database::class),
    );
});

// --- Controllers ---
$container->bind(AttemptController::class, function ($c) {
    return new AttemptController($c->make(AttemptService::class));
});

// $container->bind(ResultsController::class, function ($c) {
//     return new ResultsController(
//         $c->make(ResultsService::class),
//         $c->make(EssayReviewService::class),
//     );
// });

return $container;