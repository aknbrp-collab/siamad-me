<?php

use App\Http\Controllers\Api\LegalQuestionController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AppInfoController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\FeedbackController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\GuardianController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PdfController;
use App\Http\Controllers\Api\V1\Student\AssignmentController;
use App\Http\Controllers\Api\V1\Student\ClassController;
use App\Http\Controllers\Api\V1\Student\EvaluationController;
use App\Http\Controllers\Api\V1\Student\ExamController;
use App\Http\Controllers\Api\V1\Student\HomeController;
use App\Http\Controllers\Api\V1\Student\LetterController;
use App\Http\Controllers\Api\V1\Student\QuizController;
use App\Http\Controllers\Api\V1\StudentRecordController;
use App\Http\Middleware\VerifyCmsRequest;
use Illuminate\Support\Facades\Route;

/*
| Calls from the website CMS (v5, docs/req/siakad-api-spec.md §4). The CMS sets
| SIAKAD_BASE_URL to {APP_URL}/api/cms. Bearer API key + signature, no session.
*/
Route::prefix('cms')->middleware(['throttle:60,1', VerifyCmsRequest::class])->group(function () {
    Route::post('legal-questions', [LegalQuestionController::class, 'store'])->name('api.cms.legal-questions');
});

/*
| The mobile app API (v6 D37–D41), documented at /docs/api and in docs/API.md.
| Bearer token (Sanctum, one per device) and the X-Role header; the same Action
| classes and rules as the web pages. Breaking changes go to /api/v2 (D40).
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('app', AppInfoController::class)->middleware('throttle:api')->name('app');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-login')->name('login');

    // Signed links from other responses (photos, documents); they carry who asked and expire.
    Route::get('files/{kind}/{id}', FileController::class)->whereNumber('id')->middleware(['signed', 'throttle:api'])->name('files');
    Route::get('pdf/{document}', PdfController::class)->middleware(['signed', 'throttle:api'])->name('pdf');

    Route::middleware(['auth:sanctum', 'api.account', 'api.role', 'throttle:api'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('auth/devices', [AuthController::class, 'devices'])->name('devices');
        Route::delete('auth/devices/{device}', [AuthController::class, 'destroyDevice'])->whereNumber('device')->name('devices.destroy');
        Route::put('auth/push-token', [AuthController::class, 'pushToken'])->name('push-token');

        Route::get('me', [AccountController::class, 'me'])->name('me');
        Route::get('profile', [AccountController::class, 'profile'])->name('profile');
        Route::patch('profile', [AccountController::class, 'updateProfile'])->name('profile.update');
        Route::put('password', [AccountController::class, 'password'])->name('password');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');

        // Kritik & saran (v6 D34), every role.
        Route::get('feedback', [FeedbackController::class, 'index'])->name('feedback');
        Route::post('feedback', [FeedbackController::class, 'store'])->name('feedback.store');

        // One mahasantri's records: their own, a wali's children, academic staff (StudentAccess).
        Route::prefix('students/{student}')->name('students.')->group(function () {
            Route::get('khs', [StudentRecordController::class, 'khs'])->name('khs');
            Route::get('transcript', [StudentRecordController::class, 'transcript'])->name('transcript');
            Route::get('bills', [StudentRecordController::class, 'bills'])->name('bills');
            Route::get('activities', [StudentRecordController::class, 'activities'])->name('activities');
        });
        Route::post('bills/{bill}/transfers', [StudentRecordController::class, 'confirmTransfer'])->middleware('role:student,guardian')->name('bills.transfers');

        // Dokumen saya: mahasantri and muhadhir.
        Route::middleware('role:student,teacher')->group(function () {
            Route::get('documents', [DocumentController::class, 'index'])->name('documents');
            Route::post('documents/{document}', [DocumentController::class, 'upload'])->whereNumber('document')->name('documents.upload');
        });

        // Mahasantri (Step 6).
        Route::prefix('student')->name('student.')->middleware('role:student')->group(function () {
            Route::get('dashboard', [HomeController::class, 'dashboard'])->name('dashboard');
            Route::get('supervision', [HomeController::class, 'supervision'])->name('supervision');
            Route::get('classes', [ClassController::class, 'index'])->name('classes');
            Route::get('classes/{offering}', [ClassController::class, 'show'])->name('classes.show');
            Route::post('assignments/{assignment}/submission', [AssignmentController::class, 'submit'])->name('assignments.submit');
            Route::post('quizzes/{quiz}/attempts', [QuizController::class, 'start'])->name('quizzes.start');
            Route::get('quiz-attempts/{attempt}', [QuizController::class, 'show'])->name('quiz-attempts.show');
            Route::put('quiz-attempts/{attempt}/answers/{question}', [QuizController::class, 'answer'])->name('quiz-attempts.answer');
            Route::post('quiz-attempts/{attempt}/submit', [QuizController::class, 'submit'])->name('quiz-attempts.submit');
            Route::get('exams', [ExamController::class, 'index'])->name('exams');
            Route::get('letters', [LetterController::class, 'index'])->name('letters');
            Route::post('letter-requests', [LetterController::class, 'store'])->name('letter-requests.store');
            Route::get('evaluations', [EvaluationController::class, 'index'])->name('evaluations');
            Route::post('evaluations/{offering}', [EvaluationController::class, 'store'])->name('evaluations.store');
        });

        // Wali santri (Step 6); the children's records are under students/{student}.
        Route::get('guardian/children', [GuardianController::class, 'children'])->middleware('role:guardian')->name('guardian.children');
    });
});
