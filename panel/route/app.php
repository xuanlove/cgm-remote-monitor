<?php
declare(strict_types=1);

/**
 * The panel's public URL surface.
 *
 * Every path here existed before the refactor and must keep answering the same
 * way: the dashboard fetches /data/*.json every 30 s, bookmarks point at
 * /reports and /treatments, and the export links carry a .csv/.json suffix.
 */

use app\middleware\AdminOnly;
use app\middleware\Authenticate;
use app\middleware\CsrfGuard;
use app\middleware\GuestOnly;
use app\middleware\Throttle;
use think\facade\Route;

Route::get('/', 'Index/index');
Route::get('login', 'Auth/loginPage')->middleware(GuestOnly::class);
Route::post('login', 'Auth/login')
    ->middleware(CsrfGuard::class)
    ->middleware(Throttle::class, 'login', 20, 900);
Route::get('register', 'Auth/registerPage');
Route::post('register', 'Auth/register')
    ->middleware(CsrfGuard::class)
    ->middleware(Throttle::class, 'register', 10, 3600);

// Any method so the controller can answer 405 + Allow: POST itself.
Route::any('logout', 'Auth/logout');
Route::post('lang', 'Lang/switchLang')->middleware(CsrfGuard::class);

Route::group(function () {
    Route::get('dashboard', 'Dashboard/index');

    Route::get('data/glucose', 'Data/glucose')->ext('json');
    Route::get('data/treatments', 'Data/treatments')->ext('json');
    Route::post('data/alerts/ack', 'Data/ackAlerts')->middleware(CsrfGuard::class, 'json');

    Route::get('settings/alerts', 'Settings/alerts');
    Route::post('settings/alerts', 'Settings/saveAlerts')->middleware(CsrfGuard::class);

    Route::get('profile', 'Profile/show');
    Route::post('profile', 'Profile/save')->middleware(CsrfGuard::class);

    Route::get('entry', 'Entry/show');
    Route::post('entry', 'Entry/submit')->middleware(CsrfGuard::class);

    Route::get('treatments', 'Treatment/index');
    Route::post('treatments/save', 'Treatment/save')->middleware(CsrfGuard::class);
    Route::post('treatments/delete', 'Treatment/delete')->middleware(CsrfGuard::class);

    Route::get('profile/plans', 'Plan/index');
    Route::post('profile/plans/activate', 'Plan/activate')->middleware(CsrfGuard::class);
    Route::post('profile/plans/delete', 'Plan/delete')->middleware(CsrfGuard::class);

    Route::get('reports', 'Report/index');

    // ThinkPHP strips the request extension before matching, so the rule
    // carries the bare path and `ext()` pins which suffix it answers to.
    Route::get('export/entries', 'Export/entriesCsv')->ext('csv');
    Route::get('export/entries', 'Export/entriesJson')->ext('json');
    Route::get('export/treatments', 'Export/treatmentsCsv')->ext('csv');

    Route::get('myapi', 'Api/index');

    Route::get('admin/users', 'Admin/users')->middleware(AdminOnly::class);
    Route::post('admin/users/delete', 'Admin/deleteUser')
        ->middleware(CsrfGuard::class)
        ->middleware(AdminOnly::class);
    Route::post('admin/settings', 'Admin/saveSettings')
        ->middleware(CsrfGuard::class)
        ->middleware(AdminOnly::class);
})->middleware(Authenticate::class);

Route::miss('Error/notFound');
