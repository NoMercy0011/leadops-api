<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Messages de validation
|--------------------------------------------------------------------------
|
| Le domaine et l'interface de LeadOps sont en français ; les messages
| d'erreur doivent l'être aussi. Sans ce fichier, les libellés écrits à la
| main dans les contrôleurs sortaient bien en français, mais toute règle
| standard — `required`, `email`, `unique` — remontait en anglais. Le
| formulaire mélangeait donc les deux langues selon le champ fautif.
|
| `APP_FALLBACK_LOCALE` reste à `en` : une règle exotique non traduite ici
| s'affichera en anglais plutôt que de renvoyer la clé brute
| (« validation.mimetypes ») à l'utilisateur.
|
| Les noms de champs se traduisent dans `attributes`, en bas de fichier :
| c'est ce qui transforme « Le champ email est obligatoire » en « Le champ
| adresse email est obligatoire ».
|
*/

return [

    'accepted' => 'Le champ :attribute doit être accepté.',
    'accepted_if' => 'Le champ :attribute doit être accepté quand :other vaut :value.',
    'active_url' => "Le champ :attribute n'est pas une URL valide.",
    'after' => 'Le champ :attribute doit être une date postérieure au :date.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale au :date.',
    'alpha' => 'Le champ :attribute ne peut contenir que des lettres.',
    'alpha_dash' => 'Le champ :attribute ne peut contenir que des lettres, des chiffres, des tirets et des tirets bas.',
    'alpha_num' => 'Le champ :attribute ne peut contenir que des lettres et des chiffres.',
    'any_of' => "Le champ :attribute n'est pas valide.",
    'array' => 'Le champ :attribute doit être un tableau.',
    'ascii' => 'Le champ :attribute ne peut contenir que des caractères alphanumériques et des symboles sur un octet.',
    'before' => 'Le champ :attribute doit être une date antérieure au :date.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale au :date.',
    'between' => [
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file' => 'Le fichier :attribute doit peser entre :min et :max kilo-octets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'can' => 'Le champ :attribute contient une valeur non autorisée.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'contains' => 'Une valeur obligatoire manque dans le champ :attribute.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => "Le champ :attribute n'est pas une date valide.",
    'date_equals' => 'Le champ :attribute doit être une date égale au :date.',
    'date_format' => 'Le champ :attribute ne correspond pas au format :format.',
    'decimal' => 'Le champ :attribute doit comporter :decimal décimales.',
    'declined' => 'Le champ :attribute doit être refusé.',
    'declined_if' => 'Le champ :attribute doit être refusé quand :other vaut :value.',
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'digits' => 'Le champ :attribute doit comporter :digits chiffres.',
    'digits_between' => 'Le champ :attribute doit comporter entre :min et :max chiffres.',
    'dimensions' => "La taille de l'image :attribute n'est pas conforme.",
    'distinct' => 'Le champ :attribute contient une valeur en double.',
    'doesnt_end_with' => 'Le champ :attribute ne doit pas se terminer par l\'un des éléments suivants : :values.',
    'doesnt_start_with' => 'Le champ :attribute ne doit pas commencer par l\'un des éléments suivants : :values.',
    'email' => "Le champ :attribute n'est pas une adresse email valide.",
    'ends_with' => 'Le champ :attribute doit se terminer par l\'un des éléments suivants : :values.',
    'enum' => 'La valeur du champ :attribute est invalide.',
    'exists' => 'La valeur du champ :attribute est invalide.',
    'extensions' => 'Le champ :attribute doit avoir l\'une des extensions suivantes : :values.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'filled' => 'Le champ :attribute doit avoir une valeur.',
    'gt' => [
        'array' => 'Le champ :attribute doit contenir plus de :value éléments.',
        'file' => 'Le fichier :attribute doit peser plus de :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être supérieur à :value.',
        'string' => 'Le champ :attribute doit contenir plus de :value caractères.',
    ],
    'gte' => [
        'array' => 'Le champ :attribute doit contenir au moins :value éléments.',
        'file' => 'Le fichier :attribute doit peser au moins :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au moins :value caractères.',
    ],
    'hex_color' => "Le champ :attribute n'est pas une couleur hexadécimale valide.",
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'La valeur du champ :attribute est invalide.',
    'in_array' => "Le champ :attribute n'existe pas dans :other.",
    'in_array_keys' => 'Le champ :attribute doit contenir au moins une des clés suivantes : :values.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'ip' => "Le champ :attribute n'est pas une adresse IP valide.",
    'ipv4' => "Le champ :attribute n'est pas une adresse IPv4 valide.",
    'ipv6' => "Le champ :attribute n'est pas une adresse IPv6 valide.",
    'json' => 'Le champ :attribute doit être un document JSON valide.',
    'list' => 'Le champ :attribute doit être une liste.',
    'lowercase' => 'Le champ :attribute doit être en minuscules.',
    'lt' => [
        'array' => 'Le champ :attribute doit contenir moins de :value éléments.',
        'file' => 'Le fichier :attribute doit peser moins de :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être inférieur à :value.',
        'string' => 'Le champ :attribute doit contenir moins de :value caractères.',
    ],
    'lte' => [
        'array' => 'Le champ :attribute doit contenir au plus :value éléments.',
        'file' => 'Le fichier :attribute doit peser au plus :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être inférieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au plus :value caractères.',
    ],
    'mac_address' => "Le champ :attribute n'est pas une adresse MAC valide.",
    'max' => [
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
        'file' => 'Le fichier :attribute ne peut pas peser plus de :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne peut pas être supérieur à :max.',
        'string' => 'Le champ :attribute ne peut pas contenir plus de :max caractères.',
    ],
    'max_digits' => 'Le champ :attribute ne peut pas comporter plus de :max chiffres.',
    'mimes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'mimetypes' => 'Le champ :attribute doit être un fichier de type : :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le fichier :attribute doit peser au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'min_digits' => 'Le champ :attribute doit comporter au moins :min chiffres.',
    'missing' => 'Le champ :attribute doit être absent.',
    'missing_if' => 'Le champ :attribute doit être absent quand :other vaut :value.',
    'missing_unless' => 'Le champ :attribute doit être absent sauf si :other vaut :value.',
    'missing_with' => 'Le champ :attribute doit être absent quand :values est présent.',
    'missing_with_all' => 'Le champ :attribute doit être absent quand :values sont présents.',
    'multiple_of' => 'Le champ :attribute doit être un multiple de :value.',
    'not_in' => 'La valeur du champ :attribute est invalide.',
    'not_regex' => 'Le format du champ :attribute est invalide.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Le champ :attribute est apparu dans une fuite de données. Choisissez-en un autre.',
    ],
    'present' => 'Le champ :attribute doit être présent.',
    'present_if' => 'Le champ :attribute doit être présent quand :other vaut :value.',
    'present_unless' => 'Le champ :attribute doit être présent sauf si :other vaut :value.',
    'present_with' => 'Le champ :attribute doit être présent quand :values est présent.',
    'present_with_all' => 'Le champ :attribute doit être présent quand :values sont présents.',
    'prohibited' => 'Le champ :attribute est interdit.',
    'prohibited_if' => 'Le champ :attribute est interdit quand :other vaut :value.',
    'prohibited_if_accepted' => 'Le champ :attribute est interdit quand :other est accepté.',
    'prohibited_if_declined' => 'Le champ :attribute est interdit quand :other est refusé.',
    'prohibited_unless' => 'Le champ :attribute est interdit sauf si :other fait partie de :values.',
    'prohibits' => 'Le champ :attribute interdit la présence de :other.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_array_keys' => 'Le champ :attribute doit contenir les clés suivantes : :values.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'required_if_accepted' => 'Le champ :attribute est obligatoire quand :other est accepté.',
    'required_if_declined' => 'Le champ :attribute est obligatoire quand :other est refusé.',
    'required_unless' => 'Le champ :attribute est obligatoire sauf si :other fait partie de :values.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'required_with_all' => 'Le champ :attribute est obligatoire quand :values sont présents.',
    'required_without' => 'Le champ :attribute est obligatoire quand :values est absent.',
    'required_without_all' => 'Le champ :attribute est obligatoire quand aucun de :values n\'est présent.',
    'same' => 'Les champs :attribute et :other doivent être identiques.',
    'size' => [
        'array' => 'Le champ :attribute doit contenir :size éléments.',
        'file' => 'Le fichier :attribute doit peser :size kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string' => 'Le champ :attribute doit contenir :size caractères.',
    ],
    'starts_with' => 'Le champ :attribute doit commencer par l\'un des éléments suivants : :values.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'timezone' => "Le champ :attribute n'est pas un fuseau horaire valide.",
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uploaded' => 'Le téléversement du fichier :attribute a échoué.',
    'uppercase' => 'Le champ :attribute doit être en majuscules.',
    'url' => "Le champ :attribute n'est pas une URL valide.",
    'ulid' => "Le champ :attribute n'est pas un ULID valide.",
    'uuid' => "Le champ :attribute n'est pas un UUID valide.",

    /*
    |--------------------------------------------------------------------------
    | Messages personnalisés par champ
    |--------------------------------------------------------------------------
    |
    | Réservé aux cas où le message générique ne suffit pas à expliquer la
    | règle métier. À utiliser avec parcimonie : un message par champ et par
    | règle devient vite ingérable.
    |
    */

    'custom' => [
        'slug' => [
            'unique' => 'Cet identifiant est déjà pris par une autre entreprise.',
            'regex' => "L'identifiant ne peut contenir que des minuscules, des chiffres et des tirets.",
        ],
        'plan_id' => [
            'required' => 'Choisissez un plan pour cette entreprise.',
            'exists' => "Ce plan n'existe pas.",
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Noms des champs
    |--------------------------------------------------------------------------
    |
    | Sans cette table, `:attribute` reprend le nom technique de la colonne :
    | « Le champ default_country_code est obligatoire ». Le vocabulaire du
    | domaine restant français (prospect, projet, entreprise, rendez-vous),
    | c'est ici que le nom de colonne redevient un mot que l'utilisateur
    | reconnaît à l'écran.
    |
    */

    'attributes' => [
        'name' => 'nom',
        'email' => 'adresse email',
        'password' => 'mot de passe',
        'password_confirmation' => 'confirmation du mot de passe',
        'current_password' => 'mot de passe actuel',
        'device_name' => "nom de l'appareil",
        'token' => 'jeton',

        'slug' => 'identifiant',
        'status' => 'statut',
        'timezone' => 'fuseau horaire',
        'default_country_code' => 'indicatif pays par défaut',

        'plan_id' => 'plan',
        'company_id' => 'entreprise',
        'project_id' => 'projet',
        'variant_id' => 'variante',
        'stage_id' => 'étape',
        'prospect_id' => 'prospect',
        'question_id' => 'question',
        'user_id' => 'utilisateur',
        'assigned_user_id' => 'commercial affecté',
        'manager_id' => 'manager',

        'role' => 'rôle',
        'starts_at' => 'date de début',
        'expires_at' => "date d'échéance",
        'scheduled_at' => 'date du rendez-vous',
        'occurred_at' => 'date',

        'first_name' => 'prénom',
        'last_name' => 'nom',
        'company_name' => 'société',
        'phone' => 'téléphone',
        'address' => 'adresse',
        'source' => 'source',
        'notes' => 'notes',

        'label' => 'libellé',
        'position' => 'position',
        'required' => 'obligatoire',
        'is_won' => 'étape gagnante',
        'is_lost' => 'étape perdante',
        'color' => 'couleur',

        'product' => 'produit',
        'target' => 'cible',
        'type' => 'type',
        'value' => 'valeur',
    ],

];
