<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\WebController;
use App\Models\PublicPage;
use Illuminate\Support\Facades\Route;

Route::get('/', [WebController::class, 'home'])->name('home');
Route::get('/pages/{type}', [WebController::class, 'page']);
Route::post('/contact', [WebController::class, 'contact'])->middleware('throttle:5,1');
Route::get('/login', fn () => view('auth', ['mode' => 'login']))->name('login');
Route::post('/login', [WebController::class, 'login'])->middleware('throttle:login');
Route::get('/forgot-password', fn () => view('auth', ['mode' => 'forgot']))->name('password.request');
Route::post('/forgot-password', [WebController::class, 'forgot'])->middleware('throttle:login');
Route::get('/reset-password/{token}', fn (string $token) => view('auth', ['mode' => 'reset', 'token' => $token]))->name('password.reset');
Route::post('/reset-password', [WebController::class, 'reset'])->middleware('throttle:login');
Route::get('/email/verify/{id}/{hash}', [AuthController::class, 'verify'])->middleware(['signed', 'throttle:10,1'])->name('verification.verify');
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/email/verify', fn () => view('verify'))->name('verification.notice');
    Route::post('/email/verification-notification', [AuthController::class, 'resend'])->middleware('throttle:5,1');
    Route::post('/logout', [WebController::class, 'logout']);
    Route::middleware('verified')->group(function () {
        Route::get('/dashboard', [WebController::class, 'dashboard']);
        Route::get('/incident-map', [WebController::class, 'incidentMap'])->name('incident.map');
        Route::get('/manage/{module}', [WebController::class, 'manage']);
        Route::patch('/manage/cms/{type}', [PublicPageController::class, 'update'])->whereIn('type', PublicPage::TYPES)->name('cms.page.update');
        Route::get('/profile', fn () => view('profile'));
        Route::post('/profile/password', [AuthController::class, 'changePassword'])->name('password.update');
    });
});
