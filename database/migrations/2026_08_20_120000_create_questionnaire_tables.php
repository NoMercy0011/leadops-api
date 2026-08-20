<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invariant n°3 : les questionnaires sont configurables sans déploiement.
 *
 * D'où un schéma en deux moitiés — la définition des questions d'un côté, les
 * réponses de l'autre, dans un stockage générique. Créer une colonne par
 * question exigerait une migration par client, ce que le produit interdit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('type');
            $table->string('label');
            $table->string('help')->nullable();
            $table->boolean('required')->default(false);
            $table->unsignedInteger('position');

            // Suppression douce obligatoire : une suppression dure détruirait
            // les réponses déjà collectées, donc l'historique de qualification
            // et les statistiques passées qui en dépendent.
            $table->softDeletes();

            $table->timestamps();

            $table->unique(['project_id', 'position']);
            $table->index(['company_id', 'project_id']);
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();

            $table->string('label');

            // `value` est distincte de `label` : renommer « Moins de 30 ans »
            // en « 18-29 ans » ne doit pas invalider les réponses déjà
            // enregistrées, qui référencent la valeur et non le libellé.
            $table->string('value');

            $table->unsignedInteger('position');
            $table->timestamps();

            $table->unique(['question_id', 'value']);
            $table->index(['question_id', 'position']);
        });

        Schema::create('prospect_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();

            // Stockage générique. Le typage fort par colonne — value_int,
            // value_date… — multiplierait les colonnes nulles et imposerait une
            // migration à chaque type ajouté.
            $table->json('value')->nullable();

            $table->timestamps();

            // Une seule réponse par question et par prospect : la seconde
            // saisie remplace la première.
            $table->unique(['prospect_id', 'question_id']);
            $table->index(['company_id', 'question_id']);
        });

        // Colonne de recherche.
        //
        // MariaDB n'a pas de type JSON natif — c'est un alias de LONGTEXT — et
        // chaque lecture d'un chemin JSON réanalyse le texte. Sans cette
        // colonne générée, filtrer les prospects sur une réponse provoquerait
        // un balayage complet de table (§3.5 du plan).
        //
        // `JSON_VALUE` renvoie NULL sur un tableau : les réponses à choix
        // multiple ne sont donc pas indexées ici, et se cherchent par
        // `JSON_CONTAINS`. C'est assumé — la recherche vise les réponses
        // scalaires, qui sont l'écrasante majorité.
        Schema::table('prospect_answers', function (Blueprint $table) {
            $table->string('value_text', 255)
                ->nullable()
                ->storedAs("JSON_VALUE(`value`, '$')")
                ->after('value');

            $table->index(['company_id', 'question_id', 'value_text'], 'answers_search_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_answers');
        Schema::dropIfExists('question_options');
        Schema::dropIfExists('questions');
    }
};
