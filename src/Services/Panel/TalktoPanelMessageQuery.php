<?php

namespace Mrezdev\LaravelTalkto\Services\Panel;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator as LaravelLengthAwarePaginator;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoCurrentServiceGuard;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelMessageFilters;

class TalktoPanelMessageQuery
{
    public function paginate(TalktoPanelMessageFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        if (! $this->messagesTableExists()) {
            return new LaravelLengthAwarePaginator(
                items: [],
                total: 0,
                perPage: $perPage,
                currentPage: LaravelLengthAwarePaginator::resolveCurrentPage(),
                options: ['path' => LaravelLengthAwarePaginator::resolveCurrentPath()]
            );
        }

        $query = $this->baseMessageQuery();
        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function latest(int $limit = 10): Collection
    {
        $limit = max(1, min($limit, 100));

        if (! $this->messagesTableExists()) {
            return collect();
        }

        return $this->baseMessageQuery()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findMessage(string|int $id): ?TalktoMessage
    {
        if (! $this->messagesTableExists()) {
            return null;
        }

        $query = $this->baseMessageQuery();

        if (is_int($id) || ctype_digit((string) $id)) {
            $message = (clone $query)->whereKey((int) $id)->first();

            if ($message instanceof TalktoMessage) {
                return $message;
            }
        }

        return $query->where('message_id', (string) $id)->first();
    }

    public function attemptsFor(TalktoMessage $message): Collection
    {
        if (! $this->tableExists($this->attemptModelClass())) {
            return collect();
        }

        return $this->attemptModelClass()::query()
            ->where(function (Builder $query) use ($message): void {
                $query->where('talkto_message_id', $message->getKey())
                    ->orWhere('message_id', $message->message_id);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function eventsFor(TalktoMessage $message): Collection
    {
        if (! $this->tableExists($this->eventModelClass())) {
            return collect();
        }

        return $this->eventModelClass()::query()
            ->where(function (Builder $query) use ($message): void {
                $query->where('talkto_message_id', $message->getKey())
                    ->orWhere('message_id', $message->message_id);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function deadLetterFor(TalktoMessage $message): mixed
    {
        if (! $this->tableExists($this->deadLetterModelClass())) {
            return null;
        }

        return $this->deadLetterModelClass()::query()
            ->where(function (Builder $query) use ($message): void {
                $query->where('talkto_message_id', $message->getKey())
                    ->orWhere('message_id', $message->message_id);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function applyFilters(Builder $query, TalktoPanelMessageFilters $filters): Builder
    {
        return $query
            ->when($filters->direction !== null, fn (Builder $query) => $query->where('direction', $filters->direction))
            ->when($filters->status !== null, fn (Builder $query) => $query->where('overall_status', $filters->status))
            ->when($filters->service !== null, function (Builder $query) use ($filters): void {
                if ($filters->direction === 'outgoing') {
                    $query->where('target_service', $filters->service);

                    return;
                }

                if ($filters->direction === 'incoming') {
                    $query->where('source_service', $filters->service);

                    return;
                }

                $query->where(function (Builder $query) use ($filters): void {
                    $query->where('source_service', $filters->service)
                        ->orWhere('target_service', $filters->service);
                });
            })
            ->when($filters->command !== null, fn (Builder $query) => $query->where('command', $filters->command))
            ->when($filters->messageId !== null, function (Builder $query) use ($filters): void {
                $query->where(function (Builder $query) use ($filters): void {
                    if (ctype_digit((string) $filters->messageId)) {
                        $query->whereKey((int) $filters->messageId)
                            ->orWhere('message_id', 'like', '%'.$filters->messageId.'%');

                        return;
                    }

                    $query->where('message_id', 'like', '%'.$filters->messageId.'%');
                });
            })
            ->when($filters->correlationId !== null, fn (Builder $query) => $query->where('correlation_id', 'like', '%'.$filters->correlationId.'%'))
            ->when($filters->businessKey !== null, fn (Builder $query) => $query->where('business_key', 'like', '%'.$filters->businessKey.'%'))
            ->when($filters->idempotencyKey !== null, fn (Builder $query) => $query->where('idempotency_key', 'like', '%'.$filters->idempotencyKey.'%'))
            ->when($filters->createdFrom !== null, fn (Builder $query) => $query->where('created_at', '>=', $filters->createdFrom))
            ->when($filters->createdTo !== null, fn (Builder $query) => $query->where('created_at', '<=', $filters->createdTo));
    }

    private function baseMessageQuery(): Builder
    {
        $query = $this->messageModelClass()::query();
        $guard = app(TalktoCurrentServiceGuard::class);

        if (! $guard->shouldScopePanelToCurrentService()) {
            return $query;
        }

        $currentService = $guard->currentService();

        return $query->where(function (Builder $query) use ($currentService): void {
            $query->where('source_service', $currentService)
                ->orWhere('target_service', $currentService);
        });
    }

    private function tableExists(string $modelClass): bool
    {
        $model = new $modelClass;

        return $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable());
    }

    private function messagesTableExists(): bool
    {
        return $this->tableExists($this->messageModelClass());
    }

    private function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    private function attemptModelClass(): string
    {
        $class = config('talkto.models.attempt', TalktoAttempt::class);

        return is_string($class) && is_a($class, TalktoAttempt::class, true)
            ? $class
            : TalktoAttempt::class;
    }

    private function eventModelClass(): string
    {
        $class = config('talkto.models.event', TalktoEvent::class);

        return is_string($class) && is_a($class, TalktoEvent::class, true)
            ? $class
            : TalktoEvent::class;
    }

    private function deadLetterModelClass(): string
    {
        $class = config('talkto.models.dead_letter', TalktoDeadLetter::class);

        return is_string($class) && is_a($class, TalktoDeadLetter::class, true)
            ? $class
            : TalktoDeadLetter::class;
    }
}
