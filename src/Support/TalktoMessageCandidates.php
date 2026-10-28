<?php

namespace Mrezdev\LaravelTalkto\Support;

use Countable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use IteratorAggregate;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Traversable;

/**
 * @internal Select a fixed candidate sequence without sorting wide message rows.
 *
 * @implements IteratorAggregate<int, TalktoMessage>
 */
final class TalktoMessageCandidates implements Countable, IteratorAggregate
{
    private const BATCH_SIZE = 10;

    private function __construct(
        private readonly TalktoMessage $model,
        private readonly array $keys,
    ) {}

    /**
     * @param  Builder<TalktoMessage>  $query
     */
    public static function select(Builder $query): self
    {
        $model = $query->getModel();
        $keyName = $model->getQualifiedKeyName();
        $baseQuery = (clone $query)->toBase();
        $columns = [$keyName];

        if ($baseQuery->distinct) {
            // MySQL requires DISTINCT ordering columns in the projection.
            // These base-message scalars depend on its unique primary key.
            $orderColumns = [];

            foreach (['id', 'next_retry_at', 'locked_at', 'created_at', 'updated_at', 'retry_count'] as $attribute) {
                $qualified = $model->qualifyColumn($attribute);
                $orderColumns[$attribute] = $qualified;
                $orderColumns[$qualified] = $qualified;
            }

            foreach ($baseQuery->orders ?? [] as $order) {
                $column = $order['column'] ?? null;

                if (is_string($column) && isset($orderColumns[$column])) {
                    $columns[] = $orderColumns[$column];
                }
            }
        }

        $keys = $baseQuery->select(array_values(array_unique($columns)))->pluck($keyName)->all();

        return new self($model, $keys);
    }

    public function count(): int
    {
        return count($this->keys);
    }

    public function getIterator(): Traversable
    {
        foreach (array_chunk($this->keys, self::BATCH_SIZE) as $keys) {
            $query = $this->model->newQuery()->whereKey($keys);
            // Run after global scopes, retaining their filters but no wide sort.
            $query->getQuery()->beforeQuery(fn (QueryBuilder $query) => $query->reorder());
            $messages = [];

            foreach ($query->get() as $message) {
                $messages[(string) $message->getRawOriginal($this->model->getKeyName())] = $message;
            }

            foreach ($keys as $key) {
                if (isset($messages[(string) $key])) {
                    yield $messages[(string) $key];
                }
            }

            unset($message, $messages);
        }
    }
}
