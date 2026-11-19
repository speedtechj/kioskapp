<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kiosks', 'takeout_token')) {
            Schema::table('kiosks', function (Blueprint $table) {
                $table->uuid('takeout_token')->nullable()->unique();
            });
        }
        DB::table('kiosks')->whereNull('takeout_token')->orderBy('id')->chunkById(100, function ($kiosks) {
            foreach ($kiosks as $kiosk) {
                DB::table('kiosks')->where('id', $kiosk->id)->update(['takeout_token' => (string) Str::uuid()]);
            }
        });
        if (! Schema::hasTable('order_payments')) {
            Schema::create('order_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->uuid('batch_key');
                $table->unsignedTinyInteger('sequence');
                $table->string('method', 20);
                $table->unsignedBigInteger('amount');
                $table->string('reference', 80)->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
                $table->unique(['batch_key', 'sequence']);
            });
        }
        DB::table('orders')->where('payment_status', 'paid')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('order_payments')->whereColumn('order_payments.order_id', 'orders.id'))->orderBy('id')->chunkById(100, function ($orders) {
            foreach ($orders as $order) {
                DB::table('order_payments')->insert(['order_id' => $order->id, 'batch_key' => (string) Str::uuid(), 'sequence' => 0, 'method' => in_array($order->payment_method, ['cash', 'gcash', 'card'], true) ? $order->payment_method : 'legacy', 'amount' => $order->total, 'recorded_by' => $order->user_id, 'confirmed_at' => $order->updated_at, 'created_at' => $order->created_at, 'updated_at' => $order->updated_at]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
        Schema::table('kiosks', fn (Blueprint $table) => $table->dropColumn('takeout_token'));
    }
};
