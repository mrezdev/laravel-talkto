<?php

namespace Mrezdev\LaravelTalkto\Models\Concerns;

trait UsesTalktoDatabase
{
    public function getConnectionName()
    {
        $connection = config('talkto.database.connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : parent::getConnectionName();
    }

    protected function talktoTable(string $key, string $default): string
    {
        $table = config("talkto.database.tables.{$key}", $default);

        return is_string($table) && $table !== '' ? $table : $default;
    }

    protected function talktoDeadLetterTable(): string
    {
        $table = config('talkto.database.tables.dead_letters');

        if (is_string($table) && $table !== '') {
            return $table;
        }

        $table = config('talkto.dead_letter.table');

        return is_string($table) && $table !== '' ? $table : 'talkto_dead_letters';
    }
}
