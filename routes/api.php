<?php

use App\Http\Controllers\Api\LegalQuestionController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AppInfoController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\NotificationController;
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
    });
});
