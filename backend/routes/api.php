<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PublicPageController;
use App\Models\PublicPage;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:login');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:login');
        Route::post('reset-password', [AuthController::class, 'reset'])->middleware('throttle:login');
    });
    Route::get('public/pages/{type}', [PublicPageController::class, 'published'])->whereIn('type', PublicPage::TYPES);
    Route::get('public/{type}', [ContentController::class, 'published'])->whereIn('type', ContentController::TYPES);
    Route::post('public/contact-messages', [ContentController::class, 'contact'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'active', 'password.changed'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.logout');
        Route::post('auth/verify-email', [AuthController::class, 'resend'])->middleware('throttle:5,1');
        Route::post('me/photo', [MediaController::class, 'photo']);
        Route::get('me/photo', [MediaController::class, 'readPhoto']);
        Route::get('me', [AuthController::class, 'me'])->name('api.me');
        Route::patch('me', [AuthController::class, 'profile']);
        Route::post('me/change-password', [AuthController::class, 'changePassword'])->name('api.password.update');
        Route::post('me/change-email', [AuthController::class, 'changeEmail']);
        Route::get('agencies', [ManagementController::class, 'agencies']);
        Route::get('officers', [ManagementController::class, 'officers'])->middleware(['verified', 'can:staff']);
        Route::get('incident-categories', [ManagementController::class, 'categories']);
        Route::middleware('verified')->group(function () {
            Route::post('incidents/{incident}/attachments', [MediaController::class, 'attachment']);
            Route::get('incidents/{incident}/attachments/{attachment}', [MediaController::class, 'readAttachment']);
            Route::get('incidents/receipt/{key}', [IncidentController::class, 'receipt'])->whereUuid('key');
            Route::get('incidents', [IncidentController::class, 'index']);
            Route::post('incidents', [IncidentController::class, 'store'])->middleware('throttle:20,1');
            Route::get('incidents/{incident}', [IncidentController::class, 'show']);
            Route::patch('incidents/{incident}/status', [IncidentController::class, 'status']);
            Route::post('incidents/{incident}/verify', [IncidentController::class, 'verify']);
            Route::post('incidents/{incident}/responses', [IncidentController::class, 'response']);
            Route::post('incidents/{incident}/assign', [IncidentController::class, 'assign']);
            Route::get('dashboard/summary', [AnalyticsController::class, 'summary']);
            Route::get('analytics/heatmap', [AnalyticsController::class, 'heatmap']);
            Route::get('analytics/trends', [AnalyticsController::class, 'trends']);
            Route::get('reports', [IncidentController::class, 'index'])->middleware('can:admin');
            Route::get('reports/export', [AnalyticsController::class, 'export'])->middleware('can:admin');
            Route::get('management/users', [ManagementController::class, 'users']);
            Route::get('management/pages/{type}', [PublicPageController::class, 'show'])->whereIn('type', PublicPage::TYPES);
            Route::patch('management/pages/{type}', [PublicPageController::class, 'update'])->whereIn('type', PublicPage::TYPES);
            Route::post('management/users', [ManagementController::class, 'saveUser']);
            Route::patch('management/users/{user}', [ManagementController::class, 'saveUser']);
            Route::post('management/users/{user}/reset-password', [ManagementController::class, 'resetUser']);
            Route::patch('management/agencies/{agency}', [ManagementController::class, 'saveAgency']);
            Route::get('management/messages', [ContentController::class, 'messages']);
            Route::post('management/messages/{id}/{action}', [ContentController::class, 'messageAction']);
            Route::post('management/content/{type}/{content}/image', [MediaController::class, 'content']);
            Route::get('management/content/{type}', [ContentController::class, 'index']);
            Route::post('management/content/{type}', [ContentController::class, 'save']);
            Route::patch('management/content/{type}/{content}', [ContentController::class, 'save']);
            Route::delete('management/content/{type}/{content}', [ContentController::class, 'remove']);
            Route::get('notifications', [ManagementController::class, 'notifications']);
            Route::post('notifications/{id}/read', [ManagementController::class, 'readNotification']);
            Route::get('management/audit', [ManagementController::class, 'audit']);
            Route::post('devices', [ManagementController::class, 'tokens']);
            Route::delete('devices', [ManagementController::class, 'revokeToken']);
        });
    });
});
