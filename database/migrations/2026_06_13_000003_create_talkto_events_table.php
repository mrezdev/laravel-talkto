<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talkto_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('talkto_message_id')->nullable()->constrained('talkto_messages')->nullOnDelete();
            $table->string('message_id', 100)->nullable()->index();
            $table->string('service_name', 80)->index();
            $table->string('event_type', 100)->index();
            $table->string('old_status', 80)->nullable();
            $table->string('new_status', 80)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('reported_to_central_at')->nullable()->index();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talkto_events');
    }
};
