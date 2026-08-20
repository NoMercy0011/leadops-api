<?php

use App\Enums\AppointmentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();

            // `nullOnDelete` : un commercial qui quitte l'entreprise ne doit pas
            // emporter l'historique de ses rendez-vous. Ils deviennent
            // orphelins et sont redistribuables.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Stocké en UTC, comme toute date du produit. La conversion vers le
            // fuseau de l'entreprise se fait à la seule frontière de
            // présentation — décision §10.5, incarnée par TimezoneResolver.
            $table->timestamp('scheduled_at');

            // Durée plutôt qu'une heure de fin : un déplacement du rendez-vous
            // ne demande alors qu'une écriture, là où deux colonnes peuvent
            // diverger et produire une fin antérieure au début.
            $table->unsignedSmallInteger('duration_minutes')->default(60);

            $table->string('type');
            $table->string('status')->default(AppointmentStatus::Planned->value);
            $table->string('location')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // Axes du calendrier : par période, par commercial, par projet.
            $table->index(['company_id', 'scheduled_at']);
            $table->index(['company_id', 'user_id', 'scheduled_at']);
            $table->index(['company_id', 'project_id', 'scheduled_at']);
            $table->index(['prospect_id', 'scheduled_at']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
