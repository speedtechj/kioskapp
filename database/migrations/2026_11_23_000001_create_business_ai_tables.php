<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_ai_settings', function (Blueprint $table) {
            $table->foreignId('business_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('master_configured')->default(false);
            $table->unsignedInteger('monthly_limit_cents')->default(1000);
            $table->boolean('pause_at_limit')->default(true);
            $table->text('credentials')->nullable();
            $table->json('helpers')->nullable();
        });
        Schema::create('business_ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('helper', 32);
            $table->string('provider', 32);
            $table->string('model', 100);
            $table->unsignedBigInteger('cost_micros');
            $table->string('status', 16);
            $table->timestamp('created_at');
            $table->index(['business_id', 'created_at']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->json('translation_drafts')->nullable();
            $table->json('published_translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['translation_drafts', 'published_translations']));
        Schema::dropIfExists('business_ai_usage');
        Schema::dropIfExists('business_ai_settings');
    }
};
