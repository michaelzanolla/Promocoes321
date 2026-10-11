<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reserva acontece antes da identificação do comprador (os números ficam garantidos
 * enquanto a pessoa preenche os dados). Por isso os dados do comprador passam a ser opcionais
 * até a etapa de identificação, e o pedido guarda a forma de pagamento e o aceite das regras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('buyer_name')->nullable()->change();
            $table->string('buyer_email')->nullable()->change();
            $table->text('buyer_cpf')->nullable()->change();
            $table->string('payment_method', 20)->nullable()->after('total_cents');
            $table->dateTime('terms_accepted_at')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'terms_accepted_at']);
        });
    }
};
