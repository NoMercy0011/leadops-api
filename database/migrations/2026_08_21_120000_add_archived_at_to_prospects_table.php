<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivage d'un prospect.
 *
 * Le cycle de vie d'un prospect tenait jusqu'ici dans son étape de pipeline,
 * `converted_at` et `lost_at`. Il manquait le cas du dossier qu'on range sans
 * qu'il soit perdu : un contact injoignable depuis six mois, une ligne d'import
 * dont on ne fera rien. Le passer en « Perdu » ferait mentir le taux de perte,
 * qui est un résultat commercial et non un classement.
 *
 * Même geste que sur un projet : il quitte les listes, sort des taux, reste
 * consultable, et se défait. Rien n'est supprimé — la suppression détruirait le
 * journal d'activités dont dépendent les durées de cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('lost_at');

            // Toutes les listes filtrent désormais sur cette colonne, y compris
            // les agrégats de reporting : elle est lue à chaque requête, jamais
            // écrite en volume.
            $table->index(['company_id', 'archived_at']);
        });
    }

    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'archived_at']);
            $table->dropColumn('archived_at');
        });
    }
};
