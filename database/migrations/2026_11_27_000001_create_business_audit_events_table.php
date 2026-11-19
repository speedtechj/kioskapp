<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('business_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('kiosk_id')->nullable();
            $table->string('kiosk_name')->nullable();
            $table->unsignedBigInteger('actor_id');
            $table->string('actor_name');
            $table->string('role', 32);
            $table->string('action', 64);
            $table->string('subject')->nullable();
            $table->json('details');
            $table->timestamp('occurred_at');
            $table->index(['business_id', 'occurred_at', 'id']);
            $table->index(['business_id', 'actor_id']);
            $table->index(['business_id', 'kiosk_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_audit_events');
    }
};
