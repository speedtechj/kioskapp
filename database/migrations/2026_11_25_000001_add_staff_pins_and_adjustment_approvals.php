<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_user', function (Blueprint $table) {
            $table->string('pos_pin_hash')->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->json('adjustment_approvals')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('adjustment_approvals'));
        Schema::table('business_user', fn (Blueprint $table) => $table->dropColumn('pos_pin_hash'));
    }
};
