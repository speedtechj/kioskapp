<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('percent');
            $table->boolean('all_items');
            $table->json('product_ids');
            $table->boolean('enabled');
            $table->dateTime('announces_at');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('promotion_discount')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('promotion_discount'));
        Schema::dropIfExists('menu_promotions');
    }
};
