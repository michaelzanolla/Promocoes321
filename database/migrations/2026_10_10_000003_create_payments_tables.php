<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('provider_payment_id', 100);
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('status', 20)->default('pending');
            $table->string('provider_status', 40)->nullable();
            $table->text('pix_payload')->nullable();
            $table->string('invoice_url')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_payment_id']);
        });

        // Cada evento de webhook é gravado uma única vez: a restrição única garante a idempotência.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('provider_event_id', 100);
            $table->string('event_type', 60);
            $table->string('provider_payment_id', 100)->nullable()->index();
            $table->json('payload');
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
    }
};
