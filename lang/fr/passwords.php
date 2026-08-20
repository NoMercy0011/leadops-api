<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Réinitialisation de mot de passe
|--------------------------------------------------------------------------
|
| `PasswordResetController::reset()` relaie le statut renvoyé par le broker
| à travers `__($status)` : ce sont donc ces clés-ci qui remontent jusqu'à
| l'utilisateur, et non des chaînes écrites dans le contrôleur.
|
| `user` reste délibérément vague. La demande d'envoi de lien répond la même
| chose que l'adresse existe ou non ; un message précis ici rouvrirait par
| l'arrière la porte que `sendLink` prend soin de fermer.
|
*/

return [

    'reset' => 'Votre mot de passe a été réinitialisé.',
    'sent' => 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé.',
    'throttled' => 'Veuillez patienter avant de réessayer.',
    'token' => 'Ce lien de réinitialisation est invalide ou a expiré.',
    'user' => 'Impossible de réinitialiser le mot de passe pour cette adresse.',

];
