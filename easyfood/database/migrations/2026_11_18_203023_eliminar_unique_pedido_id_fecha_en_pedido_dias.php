<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedido_dias', function (Blueprint $tabla) {
            $tabla->dropUnique(['pedido_id', 'fecha']);
            $tabla->index(['pedido_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::table('pedido_dias', function (Blueprint $tabla) {
            $tabla->dropIndex(['pedido_id', 'fecha']);
            $tabla->unique(['pedido_id', 'fecha']);
        });
    }
};
