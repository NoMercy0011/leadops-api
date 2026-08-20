<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Normalisation des coordonnées, pour le rapprochement de doublons —
 * décision §10.1 du plan.
 *
 * Point de passage unique : c'est le pendant, côté contact, de ce que
 * `resolveTimezone` est aux dates. Un second normaliseur ailleurs dans le code
 * recréerait exactement la divergence qu'on cherche à éviter, et une
 * divergence ici est invisible — elle se manifeste par des doublons qu'on ne
 * détecte plus, pas par une erreur.
 *
 * Le parsing s'appuie sur libphonenumber plutôt que sur une expression
 * régulière maison : les plans de numérotation nationaux sont irréguliers, et
 * un normaliseur artisanal se trompe sur les préfixes, les zéros de tête et
 * les indicatifs à longueur variable.
 */
final class ContactNormalizer
{
    /**
     * Téléphone au format E.164, ou `null` si le numéro est inexploitable.
     *
     * Renvoyer `null` plutôt que la chaîne brute est délibéré : une valeur non
     * normalisée dans la colonne de rapprochement produirait de faux négatifs
     * silencieux, deux écritures différentes du même numéro ne se croisant
     * jamais. Mieux vaut ne pas rapprocher que rapprocher à moitié.
     *
     * @param  string  $defaultCountry  code ISO à deux lettres de l'entreprise,
     *                                  nécessaire pour interpréter un numéro
     *                                  saisi au format local
     */
    public static function phone(?string $phone, string $defaultCountry = 'MG'): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($phone, mb_strtoupper($defaultCountry));
        } catch (NumberParseException) {
            return null;
        }

        // `parse` réussit sur des numéros structurellement plausibles mais
        // inexistants ; la validation régionale est une étape distincte.
        if (! $util->isValidNumber($parsed)) {
            return null;
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * Email en minuscules, espaces retirés.
     *
     * La partie locale d'une adresse est théoriquement sensible à la casse,
     * mais aucun fournisseur grand public ne l'applique. Traiter
     * « Jean@Example.com » et « jean@example.com » comme deux personnes
     * différentes produirait des doublons que l'utilisateur perçoit
     * immédiatement comme un défaut du logiciel.
     */
    public static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }

        return $email;
    }
}
