<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O link secreto do pedido precisa ir nos e-mails (reserva, confirmação, consulta).
 * Antes só o hash era guardado, o que impedia reenviar o link. Agora o token fica
 * criptografado com a chave da aplicação (APP_KEY): um vazamento só do banco não expõe os links.
 * Pedidos antigos sem token recebem um novo na próxima vez que um link for enviado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('access_token_hash');
            $table->text('access_token')->nullable()->after('public_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('access_token');
            $table->char('access_token_hash', 64)->default('');
        });
    }
};
