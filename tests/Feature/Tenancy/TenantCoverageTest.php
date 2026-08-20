<?php

namespace Tests\Feature\Tenancy;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Garde-fou structurel de l'invariant n°1.
 *
 * `CompanyScopeTest` prouve que le cloisonnement fonctionne sur les modèles qui
 * le portent. Ce test-ci vérifie autre chose, et c'est ce qui manquait : que
 * **tout** modèle censé le porter le porte effectivement.
 *
 * Sans lui, ajouter une table métier en oubliant le trait passe inaperçu — les
 * tests existants continuent de réussir, et la fuite n'apparaît qu'en
 * production, sur un modèle que personne ne pense à vérifier. C'est exactement
 * ce qui était arrivé à `Subscription`.
 */
class TenantCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Modèles dispensés du trait, chacun pour une raison écrite.
     *
     * @var array<class-string<Model>, string>
     */
    private const EXEMPTS = [
        Company::class => 'Company *est* la frontière : elle ne peut pas être cloisonnée par elle-même. Son accès passe par CompanyPolicy.',
    ];

    public function test_tout_modele_portant_une_company_id_est_cloisonne(): void
    {
        $manquants = [];

        foreach ($this->modeles() as $classe) {
            $modele = new $classe;

            if (! Schema::hasColumn($modele->getTable(), 'company_id')) {
                continue;
            }

            if (array_key_exists($classe, self::EXEMPTS)) {
                continue;
            }

            if (! in_array(BelongsToCompany::class, class_uses_recursive($classe), true)) {
                $manquants[] = $classe;
            }
        }

        $this->assertSame(
            [],
            $manquants,
            "Ces modèles portent une `company_id` sans le trait BelongsToCompany :\n- "
            .implode("\n- ", $manquants)
            ."\n\nSans lui, leurs requêtes franchissent la frontière entre entreprises et "
            ."l'écriture ne rattache la ligne à aucun tenant. Ajoutez le trait, ou "
            .'inscrivez le modèle dans EXEMPTS avec sa justification.'
        );
    }

    public function test_les_dispenses_declarees_existent_encore(): void
    {
        // Empêche la liste de dérive : une dispense obsolète masquerait la
        // disparition d'une protection sur un modèle renommé.
        foreach (self::EXEMPTS as $classe => $raison) {
            $this->assertTrue(class_exists($classe), "Le modèle dispensé « {$classe} » n'existe plus.");
            $this->assertNotEmpty($raison, "La dispense de « {$classe} » doit être justifiée.");
        }
    }

    public function test_tout_modele_cloisonne_a_bien_une_colonne_company_id(): void
    {
        $orphelins = [];

        foreach ($this->modeles() as $classe) {
            if (! in_array(BelongsToCompany::class, class_uses_recursive($classe), true)) {
                continue;
            }

            $modele = new $classe;

            if (! Schema::hasColumn($modele->getTable(), 'company_id')) {
                $orphelins[] = $classe;
            }
        }

        // Le cas inverse : un trait posé sur un modèle sans colonne produit un
        // `where company_id = ?` sur une colonne absente, donc une erreur SQL
        // à la première requête d'un utilisateur d'entreprise — mais jamais
        // pour un Super Admin, qui n'est pas filtré. Le défaut ne se voit donc
        // pas en développement, où l'on se connecte souvent en Super Admin.
        $this->assertSame([], $orphelins, 'Trait BelongsToCompany sans colonne company_id : '.implode(', ', $orphelins));
    }

    /**
     * @return list<class-string<Model>>
     */
    private function modeles(): array
    {
        $classes = [];

        foreach (Finder::create()->files()->in(app_path('Models'))->name('*.php') as $fichier) {
            $classe = 'App\\Models\\'.Str::of($fichier->getRelativePathname())
                ->replace(['/', '.php'], ['\\', ''])
                ->value();

            if (! class_exists($classe)) {
                continue;
            }

            $reflexion = new ReflectionClass($classe);

            // Les traits et classes abstraites du dossier ne sont pas des
            // modèles instanciables.
            if ($reflexion->isAbstract() || ! $reflexion->isSubclassOf(Model::class)) {
                continue;
            }

            $classes[] = $classe;
        }

        return $classes;
    }
}
