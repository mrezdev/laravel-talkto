<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('talkto.dead_letter.table', 'talkto_dead_letters'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('talkto_message_id')->nullable()->unique();
            $table->string('message_id', 100)->nullable()->unique();
            $table->string('direction', 30)->nullable()->index();
            $table->string('source', 80)->nullable();
            $table->string('target', 80)->nullable();
            $table->string('command', 150)->nullable();
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('exception_class', 191)->nullable();
            $table->text('exception_message')->nullable();
            $table->string('failed_status', 80)->nullable();
            $table->unsignedInteger('original_retry_count')->default(0);
            $table->unsignedInteger('reprocess_count')->default(0);
            $table->timestamp('reprocessed_at')->nullable()->index();
            $table->string('status', 40)->default('open')->index();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('talkto.dead_letter.table', 'talkto_dead_letters'));
    }
};
