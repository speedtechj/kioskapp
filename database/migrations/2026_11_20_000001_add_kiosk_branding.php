<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('kiosks', function (Blueprint $table) {
            $table->string('app_label', 40)->nullable();
            $table->string('app_icon_key', 64)->nullable();
        });
    }
    public function down(): void {
        Schema::table('kiosks', fn(Blueprint $table) => $table->dropColumn(['app_label','app_icon_key']));
    }
};
