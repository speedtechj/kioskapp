<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('complimentary_amount')->default(0);
            $table->unsignedBigInteger('discount_amount')->default(0);
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->boolean('complimentary')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('complimentary'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['complimentary_amount', 'discount_amount']));
    }
};
