<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Point de résolution unique du fuseau horaire — couture §3.8 du plan.
 *
 * Décision §10.5 : le fuseau est porté par l'entreprise, pas par l'utilisateur.
 * Toute la logique de conversion vit ici, de sorte que le jour où il passera à
 * l'utilisateur, la migration touchera ce fichier et rien d'autre — ni les
 * requêtes du calendrier, ni les exports, ni les rapports.
 *
 * La règle qui gouverne tout le reste : **UTC en base, conversion à la seule
 * frontière de présentation**. Les dates arrivent et repartent en UTC ; ce
 * fuseau ne sert qu'à décider *ce qu'est* une journée.
 */
final class TimezoneResolver
{
    /**
     * Repli quand aucune entreprise n'est connue — le Super Admin n'en a pas.
     */
    public const DEFAULT = 'Indian/Antananarivo';

    public static function for(?User $user): string
    {
        return self::forCompany($user?->company);
    }

    public static function forCompany(?Company $company): string
    {
        $fuseau = $company?->timezone;

        // Un fuseau invalide en base — saisie ancienne, import — ne doit pas
        // faire échouer tout le calendrier : on retombe sur le défaut.
        return $fuseau !== null && in_array($fuseau, timezone_identifiers_list(), true)
            ? $fuseau
            : self::DEFAULT;
    }

    /**
     * Bornes UTC d'une journée **locale**.
     *
     * C'est la raison d'être de cette classe. Interroger la base avec
     * `whereDate('scheduled_at', today())` compare une colonne UTC à une date
     * calculée dans le fuseau du serveur : pour une entreprise à +03, les
     * rendez-vous entre 00h00 et 03h00 locales tomberaient la veille, et ceux
     * de 21h00 à minuit disparaîtraient de la journée. L'erreur est
     * silencieuse — la liste est simplement incomplète.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function dayBounds(string $timezone, ?CarbonInterface $jour = null): array
    {
        $local = $jour === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::parse($jour)->setTimezone($timezone);

        return [
            $local->startOfDay()->utc(),
            $local->endOfDay()->utc(),
        ];
    }

    /**
     * Bornes UTC d'une plage de journées locales, incluses.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public static function rangeBounds(
        string $timezone,
        CarbonInterface $debut,
        CarbonInterface $fin,
    ): array {
        [$ouverture] = self::dayBounds($timezone, $debut);
        [, $fermeture] = self::dayBounds($timezone, $fin);

        return [$ouverture, $fermeture];
    }

    /**
     * Instant présent dans le fuseau de l'entreprise.
     *
     * Sert à distinguer « à venir » de « passé » : la comparaison doit se faire
     * sur le même axe que l'affichage, sans quoi un rendez-vous du soir bascule
     * dans le passé plusieurs heures trop tôt.
     */
    public static function now(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone);
    }
}
