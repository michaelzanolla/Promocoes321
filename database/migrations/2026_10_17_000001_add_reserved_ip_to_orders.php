<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IP de quem reservou: limita quantos pedidos abertos uma mesma pessoa sem conta pode ter
     * (ReserveNumbers::MAX_OPEN_ORDERS).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('reserved_ip', 45)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('reserved_ip');
        });
    }
};
