<?php

use App\Http\Controllers\Api\V1\Admin\CompanyController;
use App\Http\Controllers\Api\V1\Admin\CompanyStatusController;
use App\Http\Controllers\Api\V1\Admin\CompanyUsageController;
use App\Http\Controllers\Api\V1\Admin\PlanController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Trois groupes, et les frontières entre eux portent les invariants :
|
| - `auth` : les rares routes sans jeton. Toute route ajoutée ici doit être
|   justifiée — le cloisonnement par entreprise ne s'y applique pas.
| - `admin` : back-office Super Admin, seul endroit qui sort du cloisonnement.
| - l'espace entreprise, cloisonné par CompanyScope et bridé en écriture par
|   `subscription.writes` quand l'abonnement ne le permet plus.
|
| RouteProtectionTest vérifie qu'aucune route ne sort de ce cadre par accident.
|
*/

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('login', [LoginController::class, 'store'])->name('auth.login');
        Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->name('auth.forgot-password');
        Route::post('reset-password', [PasswordResetController::class, 'reset'])->name('auth.reset-password');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', LogoutController::class)->name('auth.logout');
        Route::get('me', MeController::class)->name('me');

        // Back-office — hors cloisonnement, doublement gardé : middleware de
        // groupe et Policies au niveau de chaque objet.
        Route::prefix('admin')->middleware('super-admin')->name('admin.')->group(function () {
            Route::get('plans', [PlanController::class, 'index'])->name('plans.index');

            Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');
            Route::post('companies', [CompanyController::class, 'store'])->name('companies.store');
            Route::get('companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
            Route::patch('companies/{company}', [CompanyController::class, 'update'])->name('companies.update');

            Route::post('companies/{company}/suspend', [CompanyStatusController::class, 'suspend'])->name('companies.suspend');
            Route::post('companies/{company}/activate', [CompanyStatusController::class, 'activate'])->name('companies.activate');

            Route::get('companies/{company}/usage', CompanyUsageController::class)->name('companies.usage');
            Route::patch('companies/{company}/subscription', [SubscriptionController::class, 'update'])->name('companies.subscription.update');
        });

        // Espace entreprise. `subscription.writes` laisse passer les lectures
        // et bloque les écritures d'un abonnement suspendu ou expiré.
        Route::middleware('subscription.writes')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
        });
    });
});
