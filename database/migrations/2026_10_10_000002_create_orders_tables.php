<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O pedido também é a reserva: reserved_until + status (docs/fase-1-planejamento.md, seção 3).
        // promotion_id e order_id usam restrictOnDelete: pedidos nunca são apagados fisicamente.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->char('public_code', 10)->unique();
            $table->char('access_token_hash', 64);
            $table->uuid('checkout_key')->unique();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('buyer_name');
            $table->string('buyer_email')->index();
            $table->text('buyer_cpf'); // criptografado pelo model
            $table->string('buyer_phone', 20)->nullable();

            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedBigInteger('total_cents');

            $table->string('status', 20)->default('awaiting_payment');
            $table->dateTime('reserved_until');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->char('receipt_code', 16)->nullable()->unique();
            $table->timestamps();

            $table->index(['promotion_id', 'status']);
            $table->index(['status', 'reserved_until']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('promotion_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            // 1 = número preso a um pedido vivo (reservado ou vendido); NULL = histórico.
            // MySQL permite vários NULL num índice único, mas nunca dois "1" para o mesmo número:
            // é isso que impede a venda duplicada, inclusive com requisições simultâneas.
            $table->unsignedTinyInteger('active')->nullable()->default(1);

            $table->unique(['promotion_id', 'number', 'active'], 'order_items_one_active_per_number');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_active_is_one CHECK (active = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
