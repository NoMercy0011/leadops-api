<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal de configuration d'un projet.
 *
 * Le journal existant est celui du prospect : il retrace ce qui arrive à un
 * dossier. Rien ne retraçait ce qui arrive au *cadre* — une étape renommée, une
 * question retirée, un commercial sorti de l'équipe.
 *
 * L'écart est plus qu'un confort d'audit. Renommer « Qualifié » en « Étude
 * médicale » change rétroactivement le sens de tous les prospects qui y sont
 * passés, et un rapport comparé d'un trimestre à l'autre devient faux sans que
 * rien ne le signale. Le journal du prospect ne peut pas porter cette
 * information : l'événement n'appartient à aucun prospect en particulier.
 *
 * Même forme que `prospect_activities`, délibérément : le payload libre y a
 * fait ses preuves, et deux journaux de structures différentes se liraient de
 * deux façons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // `nullOnDelete` conserve la ligne après le départ de son auteur :
            // effacer le passé parce qu'un compte disparaît ferait perdre la
            // trace de décisions qui, elles, restent en vigueur.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            // La lecture se fait toujours par projet et en ordre
            // antichronologique : c'est le seul index utile.
            $table->index(['project_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_activities');
    }
};
