<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL pour le Super Admin uniquement : c'est le seul rôle qui
            // sort du périmètre d'une entreprise.
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('role')->after('email');

            // Hiérarchie manager → commerciaux, qui définit le périmètre du
            // rôle Manager. Aucune table `teams` : décision §10.2 du plan.
            // Rien ne doit lire cette colonne hors de TeamResolver.
            $table->foreignId('manager_id')
                ->nullable()
                ->after('role')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['company_id', 'role']);
            $table->index(['company_id', 'manager_id']);
        });

        // L'email est unique globalement dans le scaffolding Laravel. C'est
        // volontairement conservé : une même personne travaillant pour deux
        // entreprises clientes aurait besoin de deux comptes distincts, et une
        // unicité par entreprise ouvrirait une ambiguïté à la connexion, où
        // l'entreprise n'est pas encore connue.
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropForeign(['manager_id']);
            $table->dropIndex(['company_id', 'role']);
            $table->dropIndex(['company_id', 'manager_id']);
            $table->dropColumn(['company_id', 'role', 'manager_id']);
        });
    }
};
