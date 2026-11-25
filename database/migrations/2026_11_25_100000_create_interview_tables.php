<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entretien guidé — lot 13, contrat `cadrage/contrats/LOT-13_Entretien-guide.md` §1.
 *
 * - `questions.planned_seconds` : durée prévue d'une question, fixée par
 *   l'Admin Client ;
 * - `question_timings` : durées personnelles d'un commercial, issues d'une
 *   simulation, qui ne s'appliquent qu'à ses entretiens ;
 * - `interviews` : un entretien mené avec un prospect, son début, sa fin, et
 *   le temps prévu figé au démarrage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->unsignedSmallInteger('planned_seconds')->nullable()->after('required');
        });

        Schema::create('question_timings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seconds');
            $table->timestamps();

            $table->unique(['user_id', 'question_id']);
            $table->index(['company_id', 'question_id']);
        });

        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // `dateTime` et non `timestamp` : sous MariaDB, la première colonne
            // `timestamp` non nullable reçoit d'office ON UPDATE
            // CURRENT_TIMESTAMP, et l'heure de début serait réécrite à chaque
            // réponse enregistrée (vu en test, lot 13).
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('planned_seconds')->default(0);
            // Pas de contrainte : une question supprimée (douce) ne doit pas
            // casser un entretien déjà mené.
            $table->unsignedBigInteger('current_question_id')->nullable();
            $table->unsignedSmallInteger('answered_count')->default(0);
            $table->timestamps();

            $table->index(['prospect_id', 'finished_at']);
            $table->index(['company_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('question_timings');
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('planned_seconds');
        });
    }
};
