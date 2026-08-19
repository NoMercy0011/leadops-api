<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();

            // NULL signifie « illimité » (plan Enterprise). Un entier ne peut
            // pas porter cette nuance, et une sentinelle du type -1 finirait
            // par être comparée par erreur.
            $table->unsignedInteger('max_projects')->nullable();
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_prospects')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
