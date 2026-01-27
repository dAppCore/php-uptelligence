<?php

use Illuminate\Support\Facades\Route;
use Core\Mod\Uptelligence\View\Modal\Admin\AssetManager;
use Core\Mod\Uptelligence\View\Modal\Admin\Dashboard;
use Core\Mod\Uptelligence\View\Modal\Admin\DiffViewer;
use Core\Mod\Uptelligence\View\Modal\Admin\DigestPreferences;
use Core\Mod\Uptelligence\View\Modal\Admin\TodoList;
use Core\Mod\Uptelligence\View\Modal\Admin\VendorManager;
use Core\Mod\Uptelligence\View\Modal\Admin\WebhookManager;

/*
|--------------------------------------------------------------------------
| Uptelligence Admin Routes
|--------------------------------------------------------------------------
|
| Routes for the Uptelligence admin panel. All routes are prefixed with
| /hub/admin/uptelligence and require Hades access.
|
*/

Route::prefix('hub/admin/uptelligence')->middleware(['web', 'auth'])->group(function () {
    Route::get('/', Dashboard::class)->name('hub.admin.uptelligence');
    Route::get('/vendors', VendorManager::class)->name('hub.admin.uptelligence.vendors');
    Route::get('/todos', TodoList::class)->name('hub.admin.uptelligence.todos');
    Route::get('/diffs', DiffViewer::class)->name('hub.admin.uptelligence.diffs');
    Route::get('/assets', AssetManager::class)->name('hub.admin.uptelligence.assets');
    Route::get('/digests', DigestPreferences::class)->name('hub.admin.uptelligence.digests');
    Route::get('/webhooks', WebhookManager::class)->name('hub.admin.uptelligence.webhooks');
});
