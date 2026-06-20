<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->schema()->create($this->tableName('nonces', 'talkto_nonces'), function (Blueprint $table): void {
            $table->id();
            $table->string('nonce_hash', 64)->unique();
            $table->string('source_service', 80)->index();
            $table->string('target_service', 80)->index();
            $table->string('message_id', 100)->nullable()->index();
            $table->string('signature_version', 10)->default('v2');
            $table->string('signed_timestamp')->nullable();
            $table->timestamp('used_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists($this->tableName('nonces', 'talkto_nonces'));
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
