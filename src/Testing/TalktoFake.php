<?php

namespace Mrezdev\LaravelTalkto\Testing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Testing\Fakes\Fake;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClientWithOptions;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use PHPUnit\Framework\Assert;

/**
 * @internal Transport replacement and ledger assertions behind the Talkto facade.
 */
class TalktoFake implements Fake, TalktoHttpClientWithOptions
{
    /** @var class-string<TalktoMessage> */
    private readonly string $messageClass;

    private readonly int $startingId;

    public function __construct()
    {
        $this->messageClass = app(TalktoModelResolver::class)->message();
        $model = new $this->messageClass;
        $this->startingId = (int) $model->newQuery()->max($model->getKeyName());
    }

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        return $this->postWithOptions($url, $headers, $envelope, $timeout);
    }

    public function postWithOptions(string $url, array $headers, array $envelope, int $timeout, array $options = []): TalktoHttpResponse
    {
        return new TalktoHttpResponse(202, '{"received":true,"accepted":true,"status":"accepted"}');
    }

    public function assertSent(string $target, string $command, ?callable $callback = null): void
    {
        $count = $this->matchingCount($target, $command, $callback);

        Assert::assertGreaterThan(0, $count,
            "Expected Talkto message [{$command}] to be sent to [{$target}] at least once, but found {$count} matching messages."
        );
    }

    public function assertNotSent(string $target, string $command, ?callable $callback = null): void
    {
        $count = $this->matchingCount($target, $command, $callback);

        Assert::assertSame(0, $count,
            "Expected no Talkto message [{$command}] to be sent to [{$target}], but found {$count} matching messages."
        );
    }

    public function assertNothingSent(): void
    {
        $count = $this->messages()->count();

        Assert::assertSame(0, $count, "Expected no outgoing Talkto messages, but found {$count} messages.");
    }

    public function assertSentTimes(string $target, string $command, int $times, ?callable $callback = null): void
    {
        if ($times < 0) {
            throw new InvalidArgumentException('Talkto message count must be zero or greater.');
        }

        $count = $this->matchingCount($target, $command, $callback);

        Assert::assertSame($times, $count,
            "Expected Talkto message [{$command}] to be sent to [{$target}] {$times} times, but found {$count} matching messages."
        );
    }

    private function matchingCount(string $target, string $command, ?callable $callback): int
    {
        $alias = config("talkto.aliases.{$target}");
        $target = is_string($alias) && $alias !== '' ? $alias : $target;
        $query = $this->messages()->where('target_service', $target)->where('command', $command);

        return $callback === null ? $query->count() : $query->get()->filter($callback)->count();
    }

    /** @return Builder<TalktoMessage> */
    private function messages(): Builder
    {
        $model = new $this->messageClass;

        return $model->newQuery()
            ->where($model->getQualifiedKeyName(), '>', $this->startingId)
            ->where('direction', TalktoMessageDirection::Outgoing->value);
    }
}
