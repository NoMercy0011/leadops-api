<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jalon de qualification, requis par les KPIs du §11 de l'esquisse.
 *
 * L'esquisse demande un « taux de qualification », mais l'invariant n°2
 * interdit de reconnaître une étape à son libellé — celui-ci est saisi par le
 * client, renommable et traduisible. La sémantique passe donc par un drapeau,
 * exactement comme `is_won` et `is_lost` du lot 3.
 *
 * Le jalon `qualified_at` diffère de `converted_at` sur un point essentiel :
 * **il ne se défait pas**. Un prospect qualifié puis perdu a bel et bien été
 * qualifié, et l'oublier ferait chuter le taux de qualification à mesure que
 * les dossiers se closent — l'indicateur mesurerait alors le stock restant, pas
 * le travail accompli.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->boolean('is_qualified')->default(false)->after('is_lost');
        });

        // Même dispositif que pour is_won / is_lost : MySQL et MariaDB n'ayant
        // pas d'index unique partiel, une colonne générée vaut l'identifiant du
        // projet quand le drapeau est levé et NULL sinon — NULL n'entrant pas
        // dans une contrainte unique. Cela ferme le cas d'écritures
        // concurrentes que le contrôle applicatif laisserait passer.
        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->unsignedBigInteger('qualified_guard')
                ->storedAs('CASE WHEN is_qualified = 1 THEN project_id ELSE NULL END')
                ->nullable();

            $table->unique('qualified_guard');
        });

        Schema::table('prospects', function (Blueprint $table) {
            $table->timestamp('qualified_at')->nullable()->after('source');

            // Le reporting filtre sur ce jalon par entreprise et par période.
            $table->index(['company_id', 'qualified_at']);
            $table->index(['company_id', 'converted_at']);
            $table->index(['company_id', 'lost_at']);
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'qualified_at']);
            $table->dropIndex(['company_id', 'converted_at']);
            $table->dropIndex(['company_id', 'lost_at']);
            $table->dropColumn('qualified_at');
        });

        Schema::table('pipeline_stages', function (Blueprint $table) {
            $table->dropUnique(['qualified_guard']);
            $table->dropColumn(['qualified_guard', 'is_qualified']);
        });
    }
};
