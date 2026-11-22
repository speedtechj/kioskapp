<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_promotions', function (Blueprint $table) {
            $table->index(['business_id', 'enabled', 'starts_at', 'ends_at'], 'menu_promotions_schedule_index');
        });

        Schema::table('menu_promotions', function (Blueprint $table) {
            $table->dropUnique(['business_id']);
        });
    }

    public function down(): void
    {
        Schema::table('menu_promotions', function (Blueprint $table) {
            $table->unique('business_id');
        });

        Schema::table('menu_promotions', function (Blueprint $table) {
            $table->dropIndex('menu_promotions_schedule_index');
        });
    }
};
