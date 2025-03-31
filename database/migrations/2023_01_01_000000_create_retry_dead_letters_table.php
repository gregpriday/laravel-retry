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
        Schema::create('retry_dead_letters', function (Blueprint $table) {
            $table->id();
            $table->string('operation')->nullable();
            $table->string('error_message')->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_trace')->nullable();
            $table->json('exception_history')->nullable();
            $table->json('context')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('processing_result')->nullable();
            $table->text('processing_error')->nullable();

            $table->index('status');
            $table->index('created_at');
            $table->index('operation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('retry_dead_letters');
    }
};
