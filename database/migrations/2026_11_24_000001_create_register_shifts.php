<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('register_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kiosk_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('opening_cash');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('counted_cash')->nullable();
            $table->json('denominations')->nullable();
            $table->index(['kiosk_id', 'closed_at']);
        });

        Schema::table('order_payments', function (Blueprint $table) {
            $table->foreignId('register_shift_id')->nullable()->constrained();
            $table->foreignId('refund_shift_id')->nullable()->constrained('register_shifts');
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('register_shift_id');
            $table->dropConstrainedForeignId('refund_shift_id');
        });
        Schema::dropIfExists('register_shifts');
    }
};
