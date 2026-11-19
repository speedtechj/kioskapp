<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_super_admin')->default(false);
        });
        Schema::create('businesses', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->timestamps();
        });
        Schema::create('business_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role');
            $t->json('permissions')->nullable();
            $t->unique(['business_id', 'user_id']);
        });
        Schema::create('kiosks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('address')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('service_charge_bps')->default(0);
            $t->timestamps();
        });
        Schema::create('kiosk_user', function (Blueprint $t) {
            $t->foreignId('kiosk_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->primary(['kiosk_id', 'user_id']);
        });
        Schema::create('dining_tables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('kiosk_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->uuid('token')->unique();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('category');
            $t->text('description')->nullable();
            $t->unsignedInteger('price');
            $t->string('image')->default('/images/bowl.jpg');
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('kiosk_product', function (Blueprint $t) {
            $t->id();
            $t->foreignId('kiosk_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('stock')->default(0);
            $t->unique(['kiosk_id', 'product_id']);
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->uuid('token')->unique();
            $t->uuid('request_key')->unique();
            $t->foreignId('business_id')->constrained();
            $t->foreignId('kiosk_id')->constrained();
            $t->foreignId('dining_table_id')->nullable()->constrained();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('source');
            $t->string('status')->default('received');
            $t->string('payment_status')->default('unpaid');
            $t->string('payment_method')->nullable();
            $t->unsignedInteger('subtotal');
            $t->unsignedInteger('service_charge')->default(0);
            $t->unsignedInteger('total');
            $t->string('customer_name')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['business_id', 'kiosk_id', 'created_at']);
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->constrained();
            $t->string('name');
            $t->unsignedInteger('quantity');
            $t->unsignedInteger('unit_price');
            $t->unsignedInteger('total');
        });
    }

    public function down(): void
    {
        foreach (['order_items', 'orders', 'kiosk_product', 'products', 'dining_tables', 'kiosk_user', 'kiosks', 'business_user', 'businesses'] as $table) {
            Schema::dropIfExists($table);
        }Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_super_admin'));
    }
};
