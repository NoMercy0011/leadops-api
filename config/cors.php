<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORS — configuration ouverte, assumée
    |--------------------------------------------------------------------------
    |
    | Aucune restriction de domaine : toute origine peut appeler l'API. C'est un
    | choix explicite du projet, retenu pour garder l'implémentation simple.
    |
    | Ce que cela implique : n'importe quel site peut émettre des requêtes vers
    | l'API depuis le navigateur d'un utilisateur. L'authentification reste
    | assurée par le jeton Bearer, qu'un site tiers ne possède pas — c'est ce
    | qui limite la portée du risque. Le jeton étant scellé dans un cookie
    | httpOnly côté Next.js et jamais lisible en JavaScript, une origine tierce
    | ne peut ni le lire ni le rejouer.
    |
    | `supports_credentials` reste donc à false : on n'utilise pas de cookies de
    | session partagés avec l'API, et le combiner avec `allowed_origins: ['*']`
    | serait de toute façon refusé par les navigateurs.
    |
    | À resserrer avant la mise en production, en listant le domaine du front.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
