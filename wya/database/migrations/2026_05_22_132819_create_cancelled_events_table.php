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
        Schema::create('cancelled_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('cancelled_by')->constrained('users')->cascadeOnDelete();

            $table->text('cancellation_reason');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cancelled_events');
    }
};
