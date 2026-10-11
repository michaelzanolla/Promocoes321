<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ação que começou fora do site:
     * - promotions.series_start/series_end: numeração da ação inteira (ex.: 1 a 1000);
     *   number_start/total_numbers continuam sendo a faixa vendida no site (ex.: 501 a 1000).
     * - orders.source = 'offline': números marcados pelo administrador como vendidos fora do site,
     *   gravados em order_items para a mesma trava única impedir conflito com reservas do site.
     */
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            // Era só 0 ou 1; agora a faixa do site pode começar em qualquer número (ex.: 501).
            $table->unsignedInteger('number_start')->default(1)->change();
            $table->unsignedInteger('series_start')->nullable()->after('total_numbers');
            $table->unsignedInteger('series_end')->nullable()->after('series_start');
        });

        DB::table('promotions')->update([
            'series_start' => DB::raw('number_start'),
            'series_end' => DB::raw('number_start + total_numbers - 1'),
        ]);

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 10)->default('site')->after('promotion_id');
            $table->string('internal_note')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['source', 'internal_note']);
        });

        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['series_start', 'series_end']);
            $table->unsignedTinyInteger('number_start')->default(1)->change();
        });
    }
};
