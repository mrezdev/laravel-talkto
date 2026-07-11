<?php

namespace Mrezdev\LaravelTalkto\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Mrezdev\LaravelTalkto\Exceptions\TalktoException;

/**
 * @internal Coordinates transactions on the actual resolved Talkto model connection.
 */
class TalktoModelConnection
{
    public static function transaction(Model|string $model, Closure $callback, int $attempts = 1): mixed
    {
        return self::modelInstance($model)->getConnection()->transaction($callback, $attempts);
    }

    public static function assertSameConnection(Model|string $primary, Model|string ...$related): void
    {
        $primaryModel = self::modelInstance($primary);
        $primaryConnection = self::connectionName($primaryModel);

        foreach ($related as $candidate) {
            $candidateModel = self::modelInstance($candidate);
            $candidateConnection = self::connectionName($candidateModel);

            if ($candidateConnection === $primaryConnection) {
                continue;
            }

            throw new TalktoException(sprintf(
                'Talkto models [%s] and [%s] must use the same database connection for atomic writes; got [%s] and [%s].',
                $primaryModel::class,
                $candidateModel::class,
                $primaryConnection,
                $candidateConnection
            ));
        }
    }

    public static function connectionName(Model|string $model): string
    {
        return self::modelInstance($model)->getConnection()->getName();
    }

    private static function modelInstance(Model|string $model): Model
    {
        if ($model instanceof Model) {
            return $model;
        }

        if (! is_a($model, Model::class, true)) {
            throw new TalktoException(sprintf('Expected a Talkto Eloquent model class, got [%s].', $model));
        }

        return new $model;
    }
}
