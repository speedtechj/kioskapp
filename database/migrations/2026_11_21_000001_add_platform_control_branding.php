<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_branding', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('name', 100);
            $table->string('logo')->nullable();
            $table->string('favicon')->nullable();
        });
        Schema::table('businesses', fn (Blueprint $table) => $table->string('logo')->nullable());
        Schema::table('kiosks', fn (Blueprint $table) => $table->timestamp('archived_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('kiosks', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('logo'));
        Schema::dropIfExists('platform_branding');
    }
};
