<?php

namespace App\Services;

use App\Exceptions\SubscriptionLimitReached;
use App\Models\Company;
use App\Models\Project;
use App\Models\Prospect;
use App\Models\User;

/**
 * Contrôle des plafonds d'abonnement — invariant n°4.
 *
 * Les limites sont vérifiées ici, côté serveur, à la création. Le front peut
 * les afficher et griser des boutons, mais ne fait jamais autorité.
 *
 * Un plafond `null` signifie « illimité » (plan Enterprise) et n'est jamais
 * comparé : c'est précisément pour cette nuance que la colonne est nullable
 * plutôt que porteuse d'une sentinelle du type -1, qu'un `>=` finirait par
 * traiter comme une vraie limite.
 */
final class SubscriptionLimitService
{
    /**
     * Ressources plafonnées et manière de les compter.
     *
     * Le comptage sort explicitement du cloisonnement : le service est appelé
     * depuis le back-office Super Admin, qui n'a pas de company_id, comme
     * depuis l'espace d'une entreprise. S'appuyer sur le scope implicite
     * donnerait zéro dans le premier cas.
     *
     * Projets et prospects n'existent pas encore ; leurs compteurs arrivent
     * aux lots 3 et 4, sans autre changement que ces deux lignes.
     */
    private const COUNTERS = [
        'users' => 'countUsers',
        'projects' => 'countProjects',
        'prospects' => 'countProspects',
    ];

    /**
     * @return list<string>
     */
    public static function resources(): array
    {
        return array_keys(self::COUNTERS);
    }

    /**
     * Refuse la création si le plafond est atteint.
     *
     * @param  int  $additional  nombre d'éléments à créer — un import CSV
     *                           vérifie son volume total en amont, jamais
     *                           ligne par ligne.
     *
     * @throws SubscriptionLimitReached
     */
    public function assertCanCreate(Company $company, string $resource, int $additional = 1): void
    {
        $limit = $this->limitFor($company, $resource);

        if ($limit === null) {
            return;
        }

        $current = $this->count($company, $resource);

        if ($current + $additional > $limit) {
            throw new SubscriptionLimitReached($resource, $limit, $current);
        }
    }

    /**
     * Plafond du plan pour une ressource, `null` valant illimité.
     *
     * Une entreprise sans abonnement ne peut rien créer : c'est un état
     * anormal, traité comme un plafond à zéro plutôt que comme illimité.
     */
    public function limitFor(Company $company, string $resource): ?int
    {
        $subscription = $company->subscription;

        if ($subscription === null || $subscription->plan === null) {
            return 0;
        }

        return $subscription->plan->limitFor($resource);
    }

    public function count(Company $company, string $resource): int
    {
        $method = self::COUNTERS[$resource]
            ?? throw new \InvalidArgumentException("Ressource plafonnée inconnue : {$resource}");

        return $this->{$method}($company);
    }

    /**
     * Reste disponible, `null` si illimité. Négatif impossible : un plafond
     * abaissé sous la consommation actuelle donne zéro, pas un nombre négatif
     * qui s'afficherait mal.
     */
    public function remaining(Company $company, string $resource): ?int
    {
        $limit = $this->limitFor($company, $resource);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->count($company, $resource));
    }

    /**
     * Consommation complète, pour l'écran du back-office.
     *
     * @return array<string, array{used: int, limit: int|null, remaining: int|null}>
     */
    public function usage(Company $company): array
    {
        $usage = [];

        foreach (self::resources() as $resource) {
            $usage[$resource] = [
                'used' => $this->count($company, $resource),
                'limit' => $this->limitFor($company, $resource),
                'remaining' => $this->remaining($company, $resource),
            ];
        }

        return $usage;
    }

    private function countUsers(Company $company): int
    {
        return User::query()
            ->acrossAllCompanies()
            ->where('company_id', $company->id)
            ->count();
    }

    private function countProjects(Company $company): int
    {
        return Project::query()
            ->acrossAllCompanies()
            ->where('company_id', $company->id)
            ->count();
    }

    private function countProspects(Company $company): int
    {
        return Prospect::query()
            ->acrossAllCompanies()
            ->where('company_id', $company->id)
            ->count();
    }
}
