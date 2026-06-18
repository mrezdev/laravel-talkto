<?php

namespace Mrezdev\LaravelTalkto\Services;

use Closure;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonSerializable;
use Throwable;

class TalktoFlowBuilder
{
    protected ?string $target = null;

    protected ?string $command = null;

    protected mixed $payload = [];

    protected array $options = [];

    public function __construct(
        protected string $flowName,
        protected TalktoOutgoingMessageFactory $messageFactory
    ) {}

    public function to(string $target): self
    {
        $this->target = $target;

        return $this;
    }

    public function command(string $command): self
    {
        $this->command = $command;

        return $this;
    }

    public function payload(mixed $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    public function businessKey(?string $key): self
    {
        $this->options['business_key'] = $key;

        return $this;
    }

    public function idempotencyKey(?string $key): self
    {
        $this->options['idempotency_key'] = $key;

        return $this;
    }

    public function correlationId(?string $id): self
    {
        $this->options['correlation_id'] = $id;

        return $this;
    }

    public function parentMessageId(?string $id): self
    {
        $this->options['parent_message_id'] = $id;

        return $this;
    }

    public function schemaVersion(int $version): self
    {
        $this->options['schema_version'] = $version;

        return $this;
    }

    public function maxAttempts(int $maxAttempts): self
    {
        $this->options['max_attempts'] = $maxAttempts;

        return $this;
    }

    public function option(string $key, mixed $value): self
    {
        $this->options[$key] = $value;

        return $this;
    }

    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }

    public function send(): TalktoMessage
    {
        $this->validateReady();

        $message = $this->messageFactory->create(
            $this->target,
            $this->command,
            $this->payload,
            array_merge($this->options, [
                'flow_name' => $this->flowName,
                'source_action_status' => $this->options['source_action_status'] ?? 'succeeded_assumed',
            ])
        );

        $this->dispatchSendJob((int) $message->id);

        return $message;
    }

    public function run(Closure $sourceAction): TalktoMessage
    {
        $this->validateReady();

        try {
            $message = DB::transaction(function () use ($sourceAction): TalktoMessage {
                $result = $sourceAction();
                [$payload, $sourceResult, $sourceMeta] = $this->extractSourceResult($result);

                return $this->messageFactory->create(
                    $this->target,
                    $this->command,
                    $payload,
                    $this->optionsWithSourceResult($sourceResult, $sourceMeta)
                );
            });
        } catch (Throwable $throwable) {
            $this->recordFailedSourceAction($throwable);

            throw $throwable;
        }

        $this->dispatchSendJob((int) $message->id);

        return $message;
    }

    public function getFlowName(): string
    {
        return $this->flowName;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getCommand(): ?string
    {
        return $this->command;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    protected function validateReady(): void
    {
        if ($this->target === null || $this->target === '') {
            throw new InvalidArgumentException('Talkto flow target is required.');
        }

        if ($this->command === null || $this->command === '') {
            throw new InvalidArgumentException('Talkto flow command is required.');
        }
    }

    protected function extractSourceResult(mixed $result): array
    {
        if (is_array($result) && array_key_exists('payload', $result)) {
            return [
                $result['payload'],
                $result['result'] ?? null,
                $result['meta'] ?? null,
            ];
        }

        return [$result, null, null];
    }

    protected function optionsWithSourceResult(mixed $sourceResult, mixed $sourceMeta): array
    {
        return array_filter(array_merge($this->options, [
            'flow_name' => $this->flowName,
            'source_action_status' => 'succeeded',
            'source_result' => $this->sourceSummary($sourceResult),
            'source_meta' => $this->sourceSummary($sourceMeta),
        ]), fn (mixed $value): bool => $value !== null);
    }

    protected function recordFailedSourceAction(Throwable $throwable): void
    {
        try {
            $this->messageFactory->create(
                $this->target,
                $this->command,
                null,
                array_merge($this->options, [
                    'flow_name' => $this->flowName,
                    'source_action_status' => 'failed',
                    'transport_status' => null,
                    'overall_status' => 'failed',
                    'last_error' => $throwable->getMessage(),
                    'failed_at' => now(),
                ])
            );
        } catch (Throwable) {
            //
        }
    }

    protected function sourceSummary(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if ($value instanceof JsonSerializable) {
            return $value->jsonSerialize();
        }

        return $value;
    }

    protected function dispatchSendJob(int $messageId): void
    {
        $jobClass = $this->sendJobClass();

        $jobClass::dispatch($messageId)->afterCommit();
    }

    protected function sendJobClass(): string
    {
        $class = config('talkto.jobs.send_message', SendTalktoMessage::class);

        return is_string($class) && is_a($class, SendTalktoMessage::class, true)
            ? $class
            : SendTalktoMessage::class;
    }
}
