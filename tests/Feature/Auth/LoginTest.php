<?php

namespace Tests\Feature\Auth;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_utilisateur_valide_recoit_un_jeton_et_son_profil(): void
    {
        $entreprise = Company::factory()->create();
        Subscription::factory()->for($entreprise)->for(Plan::factory())->create();

        $utilisateur = User::factory()->adminClient()->forCompany($entreprise)->create([
            'email' => 'admin@abc.mg',
            'password' => Hash::make('motdepasse-solide'),
        ]);

        $reponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@abc.mg',
            'password' => 'motdepasse-solide',
        ]);

        $reponse->assertOk()
            ->assertJsonPath('data.user.id', $utilisateur->id)
            ->assertJsonPath('data.user.role', 'admin_client')
            ->assertJsonPath('data.user.company.id', $entreprise->id)
            ->assertJsonStructure(['data' => ['token', 'user' => ['company' => ['subscription' => ['plan']]]]]);

        $this->assertNotEmpty($reponse->json('data.token'));
    }

    public function test_le_mot_de_passe_n_est_jamais_renvoye(): void
    {
        $utilisateur = User::factory()->create([
            'email' => 'commercial@abc.mg',
            'password' => Hash::make('motdepasse-solide'),
        ]);

        $reponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'commercial@abc.mg',
            'password' => 'motdepasse-solide',
        ]);

        $reponse->assertOk();
        $this->assertArrayNotHasKey('password', $reponse->json('data.user'));
        $this->assertStringNotContainsString($utilisateur->password, $reponse->getContent());
    }

    public function test_des_identifiants_invalides_sont_refuses(): void
    {
        User::factory()->create([
            'email' => 'admin@abc.mg',
            'password' => Hash::make('motdepasse-solide'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@abc.mg',
            'password' => 'mauvais',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_le_message_ne_revele_pas_l_existence_du_compte(): void
    {
        User::factory()->create([
            'email' => 'existe@abc.mg',
            'password' => Hash::make('motdepasse-solide'),
        ]);

        $compteInconnu = $this->postJson('/api/v1/auth/login', [
            'email' => 'inconnu@abc.mg',
            'password' => 'peu-importe',
        ]);

        $mauvaisMotDePasse = $this->postJson('/api/v1/auth/login', [
            'email' => 'existe@abc.mg',
            'password' => 'peu-importe',
        ]);

        // Deux messages différents permettraient d'énumérer les comptes de la
        // plateforme en observant la réponse.
        $this->assertSame(
            $compteInconnu->json('errors.email'),
            $mauvaisMotDePasse->json('errors.email')
        );
    }

    public function test_une_entreprise_suspendue_bloque_la_connexion(): void
    {
        $entreprise = Company::factory()->suspended()->create();

        User::factory()->adminClient()->forCompany($entreprise)->create([
            'email' => 'admin@suspendue.mg',
            'password' => Hash::make('motdepasse-solide'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@suspendue.mg',
            'password' => 'motdepasse-solide',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_les_tentatives_repetees_sont_limitees(): void
    {
        User::factory()->create([
            'email' => 'cible@abc.mg',
            'password' => Hash::make('motdepasse-solide'),
        ]);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'cible@abc.mg',
                'password' => 'mauvais',
            ])->assertUnprocessable();
        }

        $bloque = $this->postJson('/api/v1/auth/login', [
            'email' => 'cible@abc.mg',
            'password' => 'motdepasse-solide',
        ]);

        // Même avec le bon mot de passe : la limitation protège contre le
        // bourrage d'identifiants, elle ne s'ouvre pas au premier succès.
        $bloque->assertUnprocessable();
        $this->assertStringContainsString('Trop de tentatives', $bloque->json('errors.email.0'));
    }

    public function test_la_deconnexion_ne_revoque_que_le_jeton_courant(): void
    {
        $utilisateur = User::factory()->create();

        $mobile = $utilisateur->createToken('mobile')->plainTextToken;
        $bureau = $utilisateur->createToken('bureau')->plainTextToken;

        $this->assertSame(2, $utilisateur->tokens()->count());

        $this->withHeader('Authorization', "Bearer {$bureau}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // Un seul jeton révoqué, celui de la requête.
        $this->assertSame(1, $utilisateur->tokens()->count());
        $this->assertSame('mobile', $utilisateur->tokens()->sole()->name);

        // `forgetGuards` est nécessaire entre deux requêtes d'une même méthode
        // de test : le guard Sanctum mémorise l'utilisateur résolu, si bien
        // qu'un jeton révoqué continuerait d'être accepté dans le test alors
        // qu'il est bien rejeté en conditions réelles, chaque requête HTTP
        // partant d'un conteneur neuf.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$mobile}")
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$bureau}")
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    }

    public function test_me_exige_une_authentification(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }
}
