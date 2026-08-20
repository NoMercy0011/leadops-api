<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des interactions.
 *
 * Le plan (§ « Modélisation ») exige cette table dès le départ plutôt que des
 * colonnes dénormalisées à rattraper plus tard : les KPIs de durée de cycle du
 * lot 7 s'appuient sur les changements d'étape horodatés, et ces données ne
 * pourront jamais être reconstituées après coup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();

            // Nullable : certains événements n'ont pas d'auteur humain — import
            // automatisé, tâche planifiée. `nullOnDelete` conserve la ligne
            // d'historique même après le départ de son auteur : effacer le
            // passé parce qu'un compte est supprimé fausserait tout le
            // reporting rétrospectif.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');

            // Détail propre au type : ancienne et nouvelle étape, texte d'une
            // note, ancien et nouveau commercial. Un schéma figé par type
            // multiplierait les colonnes nulles.
            $table->json('payload')->nullable();

            // Distinct de `created_at` : un appel passé hier peut être
            // enregistré aujourd'hui. C'est cette date qui fait foi dans la
            // chronologie affichée.
            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['prospect_id', 'occurred_at']);
            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_activities');
    }
};
