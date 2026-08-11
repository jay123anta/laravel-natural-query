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
// Public, and it has to be: this is a static JavaScript file loaded by every
// page that embeds the widget, including pages shown to signed-out visitors.
// Gating it does not protect anything — the file contains no data and no key —
// it just stops the widget rendering at all, and the failure looks like a
// broken package rather than a policy. The endpoints it CALLS are gated.
Route::get('/widget.js', [WidgetController::class, 'asset'])
    ->withoutMiddleware(['auth', \Jayanta\NaturalQuery\Http\Middleware\Authorize::class])
    ->name('widget.asset');

// Interactive demo page using the widget (config-gated; defaults to local env only)
Route::get('/demo', [WidgetController::class, 'demo'])->name('demo');

// Main query endpoint (text input)
Route::post('/text', [NaturalQueryController::class, 'textQuery'])->name('text');

// Health check
Route::get('/health', [NaturalQueryController::class, 'health'])->name('health');

// Available datasets
Route::get('/datasets', [NaturalQueryController::class, 'datasets'])->name('datasets');

// Cache management
Route::get('/cache-stats', [NaturalQueryController::class, 'cacheStats'])->name('cache.stats');
Route::post('/clear-cache', [NaturalQueryController::class, 'clearCache'])->name('cache.clear');

// Conversation (multi-turn)
Route::post('/conversation', [NaturalQueryController::class, 'conversationQuery'])->name('conversation');
Route::get('/conversation/{sessionId}', [NaturalQueryController::class, 'conversationState'])->name('conversation.state');
Route::post('/conversation/{sessionId}/rewind', [NaturalQueryController::class, 'rewindConversation'])->name('conversation.rewind');
Route::delete('/conversation/{sessionId}', [NaturalQueryController::class, 'clearConversation'])->name('conversation.clear');

// Feedback
Route::post('/feedback', [NaturalQueryController::class, 'submitFeedback'])->name('feedback');
Route::get('/feedback/stats', [NaturalQueryController::class, 'feedbackStats'])->name('feedback.stats');
