<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Pixelforge delivers at least once; the unique event id makes a redelivery a no-op.
            $table->string('event_id')->unique();
            $table->string('type', 50);
            $table->string('provider_generation_id')->nullable()->index();
            $table->unsignedSmallInteger('delivery_attempt')->default(1);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
