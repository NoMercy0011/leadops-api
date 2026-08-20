<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invariant n°2 : le pipeline est une donnée, pas un enum.
 *
 * Chaque projet définit ses propres étapes, ordonnées. Aucun libellé n'est
 * jamais testé dans la logique métier ni dans le front : la sémantique
 * terminale est portée par `is_won` et `is_lost`, précisément parce que le nom
 * est saisi par le client et peut être renommé ou traduit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();

            // company_id est redondante avec project_id, et c'est voulu : elle
            // permet à CompanyScope de filtrer sans jointure, sur une table qui
            // sera lue à chaque affichage de kanban.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('position');
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);

            // Choisie dans un jeu restreint et harmonisé côté front : un
            // sélecteur libre produirait des pipelines criards.
            $table->string('color', 20)->default('slate');

            $table->timestamps();

            $table->unique(['project_id', 'name']);

            // Deux étapes ne peuvent pas partager une position : sans cette
            // contrainte, un réordonnancement partiellement appliqué donnerait
            // un ordre d'affichage instable, dépendant du plan d'exécution.
            $table->unique(['project_id', 'position']);

            $table->index(['company_id', 'project_id', 'position']);
        });

        // Au plus une étape gagnante et une perdante par projet. Un index
        // unique partiel n'existe pas sur MySQL/MariaDB ; on passe donc par une
        // colonne générée qui vaut l'identifiant du projet quand le drapeau est
        // levé, et NULL sinon — NULL n'entrant pas dans une contrainte unique.
        //
        // La règle est aussi vérifiée en application, mais la porter en base
        // ferme le cas d'une écriture concurrente que le contrôle applicatif
        // laisserait passer.
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->unsignedBigInteger('won_guard')
                ->storedAs('CASE WHEN is_won = 1 THEN project_id ELSE NULL END')
                ->nullable();

            $table->unsignedBigInteger('lost_guard')
                ->storedAs('CASE WHEN is_lost = 1 THEN project_id ELSE NULL END')
                ->nullable();

            $table->unique('won_guard');
            $table->unique('lost_guard');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};
