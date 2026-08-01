<?php

use Illuminate\Support\Facades\Route;
use Jayanta\NaturalQuery\Http\Controllers\NaturalQueryController;
use Jayanta\NaturalQuery\Http\Controllers\WidgetController;

/*
|--------------------------------------------------------------------------
| NaturalQuery Package Routes
|--------------------------------------------------------------------------
|
| These routes are loaded by the NaturalQueryServiceProvider.
| Prefix and middleware are configured in config/naturalquery.php
|
| Default: /naturalquery/*
|
*/

// Dashboard / demo page
Route::get('/', [NaturalQueryController::class, 'index'])->name('index');

// Widget asset — served directly by the package so no publishing is needed.
// (A publishable copy also exists: php artisan vendor:publish --tag=naturalquery-assets)
//
// Handled by WidgetController, which has no constructor dependencies. Routing
// this through NaturalQueryController would construct the whole engine — and
// therefore the schema introspector — just to return a static file, so every
// page embedding <x-naturalquery::widget /> would break on an unsupported
// database driver.
Route::get('/widget.js', [WidgetController::class, 'asset'])
    ->withoutMiddleware(['auth'])
    ->name('widget.asset');

// Interactive demo page using the widget (config-gated; defaults to local env only)
Route::get('/demo', [WidgetController::class, 'demo'])->name('demo');

// Main query endpoint (text input)
Route::post('/text', [NaturalQueryController::class, 'textQuery'])->name('text');

// Voice query endpoint (audio input)
Route::post('/voice', [NaturalQueryController::class, 'voiceQuery'])->name('voice');

// Health check
Route::get('/health', [NaturalQueryController::class, 'health'])->name('health');

// Available schemes
Route::get('/schemes', [NaturalQueryController::class, 'schemes'])->name('schemes');

// Cache management
Route::get('/cache-stats', [NaturalQueryController::class, 'cacheStats'])->name('cache.stats');
Route::post('/clear-cache', [NaturalQueryController::class, 'clearCache'])->name('cache.clear');

// Conversation (multi-turn)
Route::post('/conversation', [NaturalQueryController::class, 'conversationQuery'])->name('conversation');
Route::delete('/conversation/{sessionId}', [NaturalQueryController::class, 'clearConversation'])->name('conversation.clear');

// Feedback
Route::post('/feedback', [NaturalQueryController::class, 'submitFeedback'])->name('feedback');
Route::get('/feedback/stats', [NaturalQueryController::class, 'feedbackStats'])->name('feedback.stats');
