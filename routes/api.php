<?php

declare(strict_types=1);

/**
 * Uptelligence Module API Routes
 *
 * Webhook endpoints for receiving vendor release notifications.
 */

use Core\Mod\Uptelligence\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Uptelligence Webhooks (Public - No Auth Required)
|--------------------------------------------------------------------------
|
| External webhook endpoints for receiving release notifications from
| GitHub, GitLab, npm, Packagist, and other vendor systems.
| Authentication is handled via signature verification using the
| webhook's secret key.
|
*/

Route::prefix('uptelligence/webhook')->name('api.uptelligence.webhooks.')->group(function () {
    Route::post('/{webhook}', [WebhookController::class, 'receive'])
        ->name('receive')
        ->middleware('throttle:uptelligence-webhooks');

    Route::post('/{webhook}/test', [WebhookController::class, 'test'])
        ->name('test')
        ->middleware('throttle:uptelligence-webhooks');
});

Route::post('/webhooks/uptelligence/{vendor}', [WebhookController::class, 'receiveVendor'])
    ->name('webhooks.uptelligence.receive')
    ->middleware('throttle:uptelligence-webhooks');
