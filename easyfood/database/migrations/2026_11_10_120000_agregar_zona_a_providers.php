<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El front muestra la zona de cada comedor (`Comedor.zona`), pero `providers` no la tenía.
 * Es nullable para no romper filas que ya existan; el panel la pedirá al dar de alta un comedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('zona', 100)->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('zona');
        });
    }
};
