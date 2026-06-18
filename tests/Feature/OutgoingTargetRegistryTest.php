<?php

use Ibake\TalktoReliable\Contracts\TalktoOutgoingTargetRegistryContract;
use Ibake\TalktoReliable\Exceptions\InvalidTalktoOutgoingTarget;
use Ibake\TalktoReliable\Exceptions\UnknownTalktoOutgoingTarget;
use Ibake\TalktoReliable\Jobs\SendTalktoMessage;
use Ibake\TalktoReliable\Models\TalktoMessage;
use Ibake\TalktoReliable\Services\TalktoOutgoingEnvelopeBuilder;
use Ibake\TalktoReliable\Services\TalktoOutgoingMessageFactory;
use Ibake\TalktoReliable\Services\TalktoOutgoingTarget;
use Ibake\TalktoReliable\Services\TalktoOutgoingTargetRegistry;
use Ibake\TalktoReliable\Services\TalktoPayloadHasher;
use Ibake\TalktoReliable\Services\TalktoRetryPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'testing',
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.max_attempts' => 5,
        'talkto.outgoing' => [],
        'talkto.aliases' => [],
    ]);
});

test('config based outgoing target resolves to value object', function (): void {
    config(['talkto.outgoing.peer' => [
        'url' => 'https://peer.test',
        'secret' => 'secret',
        'endpoint' => '/talkto/receive',
        'headers' => ['X-Custom' => 'custom'],
        'timeout' => 12,
        'mode' => 'reliable',
        'option_key' => 'option-value',
    ]]);

    $target = app(TalktoOutgoingTargetRegistryContract::class)->get('peer');

    expect($target)->toBeInstanceOf(TalktoOutgoingTarget::class)
        ->and($target->name())->toBe('peer')
        ->and($target->url())->toBe('https://peer.test')
        ->and($target->endpointUrl())->toBe('https://peer.test/talkto/receive')
        ->and($target->secret())->toBe('secret')
        ->and($target->headers())->toBe(['X-Custom' => 'custom'])
        ->and($target->timeout())->toBe(12)
        ->and($target->transport())->toBe('reliable')
        ->and($target->options())->toBe(['option_key' => 'option-value']);
});

test('programmatic registration through contract overrides config and shares singleton', function (): void {
    config(['talkto.outgoing.peer' => [
        'url' => 'https://config.test',
        'secret' => 'config-secret',
    ]]);

    $contract = app(TalktoOutgoingTargetRegistryContract::class);
    $concrete = app(TalktoOutgoingTargetRegistry::class);
    $contract->register('peer', [
        'url' => 'https://registered.test',
        'secret' => 'registered-secret',
        'endpoint' => '/receive',
    ]);

    expect($contract)->toBe($concrete)
        ->and($concrete->get('peer')->endpointUrl())->toBe('https://registered.test/receive')
        ->and($concrete->get('peer')->secret())->toBe('registered-secret');
});

test('unknown outgoing target resolve and get behavior is explicit', function (): void {
    $registry = app(TalktoOutgoingTargetRegistryContract::class);

    expect($registry->resolve('missing'))->toBeNull();

    $registry->get('missing');
})->throws(UnknownTalktoOutgoingTarget::class, 'missing');

test('invalid outgoing target reports safe target name without secret leakage', function (): void {
    config(['talkto.outgoing.broken' => 'not-an-array']);

    try {
        app(TalktoOutgoingTargetRegistryContract::class)->get('broken');
    } catch (InvalidTalktoOutgoingTarget $exception) {
        expect($exception->getMessage())->toContain('broken')
            ->and($exception->getMessage())->not->toContain('secret');

        return;
    }

    throw new RuntimeException('Expected invalid target exception.');
});

test('existing outgoing send flow uses registry resolved target data', function (): void {
    config(['talkto.outgoing.peer' => [
        'url' => 'https://peer.test',
        'secret' => 'secret',
        'endpoint' => '/api/talkto/receive',
        'headers' => ['X-Custom' => 'custom'],
        'timeout' => 7,
    ]]);
    Http::fake(fn (Request $request) => Http::response([
        'received' => true,
        'status' => 'accepted',
    ], 200));

    $message = app(TalktoOutgoingMessageFactory::class)->create(
        target: 'peer',
        command: 'domain.command',
        payload: ['id' => 123],
        options: ['message_id' => 'outgoing-target-send']
    );

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://peer.test/api/talkto/receive'
            && $request->hasHeader('X-Custom', 'custom')
            && $request->hasHeader('X-Talkto-Signature')
            && ($request->data()['payload_hash'] ?? null) === app(TalktoPayloadHasher::class)->hash(['id' => 123]);
    });
    expect($message->fresh()->overall_status)->toBe('destination_received');
});

test('existing config missing url behavior remains a safe job failure', function (): void {
    config(['talkto.outgoing.peer' => [
        'secret' => 'secret',
    ]]);
    $message = outgoingTargetMessage('outgoing-target-missing-url');

    (new SendTalktoMessage($message->id))->handle(app(TalktoOutgoingEnvelopeBuilder::class), app(TalktoRetryPolicy::class));

    $message = $message->fresh();

    expect($message->overall_status)->toBe('failed_retryable')
        ->and($message->last_error)->toContain('URL is not configured')
        ->and($message->next_retry_at)->not->toBeNull();
});

test('aliases remain compatible with outgoing target resolution', function (): void {
    config([
        'talkto.aliases.peer-alias' => 'peer',
        'talkto.outgoing.peer' => [
            'url' => 'https://peer.test',
            'secret' => 'secret',
        ],
    ]);

    $message = app(TalktoOutgoingMessageFactory::class)->create('peer-alias', 'domain.command');

    expect($message->target_service)->toBe('peer')
        ->and(app(TalktoOutgoingTargetRegistryContract::class)->get('peer-alias')->name())->toBe('peer');
});

function outgoingTargetMessage(string $messageId, array $attributes = []): TalktoMessage
{
    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'direction' => 'outgoing',
        'source_service' => 'testing',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'payload' => ['id' => $messageId],
        'payload_hash' => app(TalktoPayloadHasher::class)->hash(['id' => $messageId]),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));
}
