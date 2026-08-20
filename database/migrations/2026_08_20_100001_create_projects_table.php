<?php

use App\Enums\ProjectStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // `nullOnDelete` et non `cascade` : supprimer une variante du
            // catalogue ne doit pas emporter les projets qui la portaient.
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('product')->nullable();
            $table->string('target')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default(ProjectStatus::Active->value);
            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'variant_id']);
        });

        // Équipe affectée au projet. Pas de table `teams` : décision §10.2 —
        // la hiérarchie vit dans `users.manager_id`, l'affectation ici.
        Schema::create('project_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
    }
};
