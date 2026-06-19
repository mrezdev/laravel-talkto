<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->tableName('attempts', 'talkto_attempts'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('talkto_message_id')->nullable();
            $table->foreign('talkto_message_id')
                ->references('id')
                ->on($this->tableName('messages', 'talkto_messages'))
                ->nullOnDelete();
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
        $this->schema()->dropIfExists($this->tableName('attempts', 'talkto_attempts'));
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
