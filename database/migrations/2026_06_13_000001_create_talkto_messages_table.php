<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->tableName('messages', 'talkto_messages'), function (Blueprint $table) {
            $table->id();
            $table->string('message_id', 100)->unique();
            $table->string('correlation_id', 100)->nullable()->index();
            $table->string('parent_message_id', 100)->nullable()->index();
            $table->string('direction', 30)->index();
            $table->string('source_service', 80);
            $table->string('target_service', 80);
            $table->string('command', 150)->index();
            $table->string('business_key', 191)->nullable()->index();
            $table->string('idempotency_key', 191)->nullable()->index();
            $table->json('payload')->nullable();
            $table->string('payload_hash', 100)->nullable();
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('source_action_status', 80)->nullable()->index();
            $table->string('transport_status', 80)->nullable()->index();
            $table->string('destination_receive_status', 80)->nullable()->index();
            $table->string('destination_action_status', 80)->nullable()->index();
            $table->string('overall_status', 80)->default('created')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(6);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->integer('last_http_status')->nullable();
            $table->text('last_error')->nullable();
            $table->text('last_response')->nullable();
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable()->index();
            $table->timestamp('locked_at')->nullable();
            $table->string('locked_by', 100)->nullable();
            $table->timestamps();

            $table->index(['source_service', 'target_service']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->tableName('messages', 'talkto_messages'));
    }

    private function schema(): \Illuminate\Database\Schema\Builder
    {
        $connection = config('talkto.database.connection');

        return is_string($connection) && $connection !== ''
            ? Schema::connection($connection)
            : Schema::getFacadeRoot();
    }

    private function tableName(string $key, string $default): string
    {
        $table = config("talkto.database.tables.{$key}", $default);

        return is_string($table) && $table !== '' ? $table : $default;
    }
};
