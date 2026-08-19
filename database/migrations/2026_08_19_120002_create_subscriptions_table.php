<?php

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status')->default(SubscriptionStatus::Active->value);
            $table->timestamp('starts_at');

            // NULL = sans échéance. Distinct d'une date lointaine, qui serait
            // interprétée comme une expiration réelle par les relances.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // Une entreprise n'a qu'un abonnement courant. L'historique des
            // changements de plan relèvera d'une table dédiée si le besoin
            // apparaît ; le MVP n'en a pas l'usage.
            $table->unique('company_id');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
