<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            // Signed nanodollars: positive adds credit, negative spends it.
            $table->bigInteger('amount_nanos');
            $table->bigInteger('balance_after_nanos');
            $table->timestamps();

            // The database itself guarantees a generation is never charged twice.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
