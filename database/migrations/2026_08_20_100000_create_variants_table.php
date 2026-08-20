<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Décision §10.4 du plan : la variante est une table de référence propre à
 * l'entreprise, et non un champ texte libre sur le projet.
 *
 * Elle sert d'axe de reporting (§11 de l'esquisse). Un axe d'agrégation bâti
 * sur de la saisie libre se dégrade mécaniquement : « Particulier »,
 * « particuliers » et « Particulier  » deviennent trois lignes distinctes, et
 * le rapport ne tombe pas en panne — il devient faux en silence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // Unicité par entreprise et non globale : deux clients peuvent
            // parfaitement nommer une variante « Particulier ».
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variants');
    }
};
