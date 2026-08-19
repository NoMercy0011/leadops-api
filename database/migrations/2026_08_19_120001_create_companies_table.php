<?php

use App\Enums\CompanyStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default(CompanyStatus::Active->value);

            // Fuseau au niveau entreprise : décision §10.5 du plan d'action.
            // Toutes les dates sont stockées en UTC et converties ici à
            // l'affichage, via l'unique point de résolution prévu côté front.
            $table->string('timezone')->default('Indian/Antananarivo');

            // Sert à normaliser les numéros de téléphone en E.164 lors de la
            // détection de doublons (décision §10.1), qui ne peut pas deviner
            // le pays à partir d'un numéro saisi au format local.
            $table->string('default_country_code', 2)->default('MG');

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
