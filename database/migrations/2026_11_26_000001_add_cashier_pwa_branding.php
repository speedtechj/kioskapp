<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->uuid('cashier_icon_key')->nullable();
            $table->uuid('cashier_favicon_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kiosks', fn (Blueprint $table) => $table->dropColumn(['cashier_icon_key', 'cashier_favicon_key']));
    }
};
