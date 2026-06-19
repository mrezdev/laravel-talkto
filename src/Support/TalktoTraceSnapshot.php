<?php

namespace Mrezdev\LaravelTalkto\Support;

final readonly class TalktoTraceSnapshot
{
    public function __construct(
        public bool $found,
        public array $query,
        public ?array $anchorMessage,
        public ?string $correlationId,
        public array $relatedMessages,
        public array $attempts,
        public array $events,
        public array $deadLetters,
        public array $timeline,
        public array $warnings,
        public bool $truncated,
        public int $limit,
    ) {}

    public function toArray(): array
    {
        return [
            'found' => $this->found,
            'query' => $this->query,
            'anchor_message' => $this->anchorMessage,
            'correlation_id' => $this->correlationId,
            'related_messages' => $this->relatedMessages,
            'attempts' => $this->attempts,
            'events' => $this->events,
            'dead_letters' => $this->deadLetters,
            'timeline' => $this->timeline,
            'warnings' => $this->warnings,
            'truncated' => $this->truncated,
            'limit' => $this->limit,
        ];
    }
}
