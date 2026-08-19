<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Deux groupes seulement, et la frontière entre eux est l'invariant n°1 :
|
| - `auth` : les rares routes accessibles sans jeton (connexion, mot de passe
|   oublié). Toute route ajoutée ici doit être justifiée, car le cloisonnement
|   par entreprise ne s'y applique pas — aucun utilisateur n'est authentifié.
| - le reste, systématiquement derrière `auth:sanctum`.
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
    });
});
