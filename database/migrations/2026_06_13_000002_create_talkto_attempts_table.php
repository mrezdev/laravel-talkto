<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talkto_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talkto_message_id')->nullable()->constrained('talkto_messages')->nullOnDelete();
            $table->string('message_id', 100)->nullable()->index();
            $table->string('stage', 80)->index();
            $table->unsignedInteger('attempt_no')->default(1);
            $table->string('status', 80)->index();
            $table->integer('http_status')->nullable();
            $table->string('error_class', 191)->nullable();
            $table->text('error_message')->nullable();
            $table->text('request_excerpt')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talkto_attempts');
    }
};
