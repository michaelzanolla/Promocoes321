<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();
            $table->string('prize')->nullable();
            $table->string('category', 60)->nullable()->index();
            $table->string('image_path')->nullable();

            // Valores em centavos. Números vão de number_start até number_start + total_numbers - 1.
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedTinyInteger('number_start')->default(1);
            $table->unsignedInteger('total_numbers');
            $table->unsignedSmallInteger('min_per_order')->default(1);
            $table->unsignedSmallInteger('max_per_order')->nullable();
            $table->boolean('allow_random')->default(true);
            $table->unsignedSmallInteger('reservation_minutes')->default(15);
            $table->json('payment_methods');

            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 20)->default('draft');

            $table->text('rules')->nullable();
            $table->text('draw_info')->nullable();
            $table->string('legal_reference')->nullable();
            $table->boolean('is_demo')->default(false);

            $table->unsignedInteger('result_number')->nullable();
            $table->dateTime('result_published_at')->nullable();
            $table->string('result_proof_url')->nullable();
            $table->text('result_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
        });

        Schema::create('promotion_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('alt')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_images');
        Schema::dropIfExists('promotions');
    }
};
