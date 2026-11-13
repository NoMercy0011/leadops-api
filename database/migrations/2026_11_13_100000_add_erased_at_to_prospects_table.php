<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marque d'une fiche dont les données personnelles ont été effacées — lot 9,
 * règle R4.
 *
 * Pas d'index : la colonne ne sert jamais de filtre de liste. Elle se lit sur
 * une fiche déjà chargée, pour refuser l'écriture et afficher « Prospect
 * effacé ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropColumn('erased_at');
        });
    }
};
