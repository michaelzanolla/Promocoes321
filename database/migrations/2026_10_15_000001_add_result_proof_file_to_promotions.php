<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Comprovação do sorteio enviada como arquivo (foto, print ou PDF da ata), além do link.
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->string('result_proof_path')->nullable()->after('result_proof_url');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn('result_proof_path');
        });
    }
};
