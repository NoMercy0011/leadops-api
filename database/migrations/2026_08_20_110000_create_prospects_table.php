<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // `restrictOnDelete` : supprimer une étape encore occupée laisserait
            // des prospects sans position dans le pipeline. Le contrôleur
            // refuse déjà cette suppression ; la contrainte ferme le cas d'une
            // suppression concurrente.
            $table->foreignId('stage_id')->constrained('pipeline_stages')->restrictOnDelete();

            // `nullOnDelete` : un commercial qui quitte l'entreprise ne doit
            // pas emporter ses prospects. Ils redeviennent non affectés, prêts
            // à être redistribués.
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Colonnes de rapprochement, calculées à l'écriture — décision
            // §10.1. Sans elles, « +261 34 12 345 67 » et « 0341234567 » sont
            // deux chaînes différentes et la détection de doublons ne trouve
            // jamais rien.
            $table->string('phone_normalized', 32)->nullable();
            $table->string('email_normalized')->nullable();

            $table->string('address')->nullable();
            $table->string('source')->nullable();
            $table->text('notes')->nullable();

            // Prochaine action planifiée. Volontairement une colonne et non un
            // dérivé : c'est une intention, pas un fait passé, donc rien dans
            // l'historique ne permet de la reconstituer.
            $table->timestamp('next_action_at')->nullable();
            $table->string('next_action_note')->nullable();

            // Horodatages de sortie de parcours. La date de conversion sert au
            // calcul des durées de cycle ; l'étape seule ne dit pas *quand* le
            // passage a eu lieu.
            $table->timestamp('converted_at')->nullable();
            $table->timestamp('lost_at')->nullable();

            $table->timestamps();

            // Axes de filtrage de toutes les listes — cf. §5 du plan.
            $table->index(['company_id', 'project_id', 'stage_id']);
            $table->index(['company_id', 'assigned_user_id']);
            $table->index(['company_id', 'next_action_at']);

            // Non uniques : un même contact peut exister dans plusieurs
            // projets (§3 de l'esquisse). L'index sert le rapprochement, qui
            // reste une suggestion faite à l'utilisateur, pas un blocage.
            $table->index(['company_id', 'phone_normalized']);
            $table->index(['company_id', 'email_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
    }
};
