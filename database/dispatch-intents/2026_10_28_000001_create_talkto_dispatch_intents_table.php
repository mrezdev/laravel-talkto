<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->tableName(), function (Blueprint $table): void {
            $table->id();
            $table->char('intent_key', 64)->unique();
            $table->string('message_class', 255);
            $table->string('message_table', 191);
            $table->string('message_key_name', 100);
            $table->string('message_key', 191);
            $table->string('message_id', 100);
            $table->string('direction', 30);
            $table->string('owner', 80);
            $table->string('purpose', 32)->default('initial');
            $table->string('job_class', 255);
            $table->string('queue_connection', 100);
            $table->string('queue_name', 191);
            $table->string('queue_driver', 30);
            $table->timestamp('available_at')->nullable();
            $table->string('status', 30)->default('ready');
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('claim_expires_at')->nullable();
            $table->timestamp('publication_started_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('publication_attempts')->default(0);
            $table->string('last_error_code', 80)->nullable();
            $table->timestamps();

            // No FK assumes the configured message table's key type or lifetime.
            $table->index(['owner', 'status', 'created_at', 'id'], 'talkto_dispatch_intents_owner_state_idx');
            $table->index('message_id', 'talkto_dispatch_intents_message_id_idx');
            $table->index(['status', 'claim_expires_at'], 'talkto_dispatch_intents_lease_idx');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->tableName());
    }

    private function schema(): Builder
    {
        $connection = config('talkto.database.connection');

        return is_string($connection) && $connection !== ''
            ? Schema::connection($connection)
            : Schema::getFacadeRoot();
    }

    private function tableName(): string
    {
        $table = config('talkto.database.tables.dispatch_intents', 'talkto_dispatch_intents');

        return is_string($table) && $table !== '' ? $table : 'talkto_dispatch_intents';
    }
};
