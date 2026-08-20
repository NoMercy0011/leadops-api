<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Messages d'authentification
|--------------------------------------------------------------------------
|
| LoginController n'utilise pas `auth.failed` : il émet son propre message,
| volontairement identique que l'adresse soit inconnue ou le mot de passe
| faux, pour ne pas transformer la connexion en oracle d'énumération des
| comptes. Ces clés servent aux mécanismes internes de Laravel qui les
| réclament malgré tout.
|
*/

return [

    'failed' => 'Ces identifiants ne correspondent à aucun compte.',
    'password' => 'Le mot de passe est incorrect.',
    'throttle' => 'Trop de tentatives. Réessayez dans :seconds secondes.',

];
