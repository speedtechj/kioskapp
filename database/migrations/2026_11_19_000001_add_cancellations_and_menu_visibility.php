<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'visible_kiosk_ids')) {
            Schema::table('products', fn (Blueprint $t) => $t->json('visible_kiosk_ids')->nullable());
        }
        if (! Schema::hasColumn('orders', 'cancelled_at')) {
            Schema::table('orders', function (Blueprint $t) {
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('cancelled_by')->nullable();
            });
        }
        if (! Schema::hasColumn('order_payments', 'refunded_at')) {
            Schema::table('order_payments', function (Blueprint $t) {
                $t->timestamp('refunded_at')->nullable();
                $t->unsignedBigInteger('refunded_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_payments', fn (Blueprint $t) => $t->dropColumn(['refunded_at', 'refunded_by']));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['cancelled_at', 'cancelled_by']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('visible_kiosk_ids'));
    }
};
