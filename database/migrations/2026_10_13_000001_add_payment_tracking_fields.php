<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // QR Code do Pix como o provedor entrega (imagem PNG em base64).
            $table->mediumText('pix_qr_base64')->nullable()->after('pix_payload');
            $table->string('failure_reason')->nullable()->after('provider_status');
            // Última consulta ao provedor: evita consultar de novo a cada atualização da página.
            $table->dateTime('last_checked_at')->nullable()->after('refunded_at');
            $table->index(['status', 'last_checked_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('refunded_at')->nullable()->after('cancelled_at');
            $table->string('review_reason')->nullable()->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['status', 'last_checked_at']);
            $table->dropColumn(['pix_qr_base64', 'failure_reason', 'last_checked_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['refunded_at', 'review_reason']);
        });
    }
};
