<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('toppings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedInteger('price');
            $table->boolean('active')->default(true);
            $table->index(['business_id', 'active']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->json('topping_lines')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('topping_lines'));
        Schema::dropIfExists('toppings');
    }
};
