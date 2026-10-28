<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Exceptions\TalktoInvalidEnvelopeFieldException;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Services\TalktoCallbackStatusInspector;
use Mrezdev\LaravelTalkto\Services\TalktoEnvelopeFieldValidator;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoResultCallbackEnvelopeBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoRetryPolicy;
use Mrezdev\LaravelTalkto\Services\TalktoTraceReporter;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    $this->travelTo(now()->startOfSecond());
    config([
        'talkto.service' => 'sender',
        'talkto.retry.enabled' => true,
        'talkto.retry.outgoing_enabled' => true,
        'talkto.retry.jitter_seconds' => 0,
        'talkto.retry.backoff_seconds' => [17, 41],
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v1',
        'talkto.security.accept_versions' => ['v1'],
        'talkto.outgoing.peer' => ['url' => 'https://peer.test', 'secret' => 'test-only-shared-secret'],
        'talkto.outgoing.sender' => ['url' => 'https://sender.test', 'secret' => 'test-only-shared-secret'],
        'talkto.incoming.peer' => ['secret' => 'test-only-shared-secret', 'allowed_commands' => ['talkto.result' => ['driver' => 'none']]],
    ]);
});

test('delayed transport outcomes preserve every field of a protected persisted message', function (string $terminal, string $outcome): void {
    $message = terminalProtectionMessage();
    $snapshot = null;
    $client = new TerminalProtectionHttpClient(function () use ($message, $terminal, $outcome, &$snapshot): TalktoHttpResponse {
        $persisted = $message->fresh();
        expect($persisted->overall_status)->toBe('sending');
        $persisted->forceFill([
            'overall_status' => $terminal,
            'destination_action_status' => $terminal === 'failed_final' ? 'failed_final' : 'succeeded',
            'transport_status' => 'sent',
            'completed_at' => now()->subMinute(),
            'failed_at' => null,
            'last_error' => null,
            'last_http_status' => 202,
            'last_response' => 'authoritative completion receipt',
            'next_retry_at' => null,
            'next_attempt_at' => null,
            'retry_count' => 2,
            'locked_at' => null,
            'locked_by' => null,
        ])->save();
        $snapshot = terminalProtectionSnapshot($persisted);

        if ($outcome === 'timeout') {
            throw new RuntimeException('Connection timed out after destination completion.');
        }

        return new TalktoHttpResponse((int) $outcome, 'late transport failure body');
    });

    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));

    expect(terminalProtectionSnapshot($message->fresh()))->toBe($snapshot)
        ->and(TalktoAttempt::query()->where('message_id', $message->message_id)->count())->toBe(1)
        ->and(TalktoDeadLetter::query()->where('message_id', $message->message_id)->count())->toBe(0)
        ->and(TalktoEvent::query()->where('message_id', $message->message_id)->whereIn('event_type', ['message_send_failed', 'retry_scheduled', 'retry_exhausted', 'retry_not_scheduled'])->count())->toBe(0);

    terminalProtectionAssertIgnoredAttempt($message, $outcome, $terminal);
})->with(['completed', 'succeeded', 'failed_final'])->with(['500', '503', 'timeout']);

test('signed callback commits inside the actual HTTP client before the transport result', function (string $callbackStatus, string $outcome): void {
    $message = terminalProtectionMessage();
    $snapshot = null;
    $client = new TerminalProtectionHttpClient(function () use ($message, $callbackStatus, $outcome, &$snapshot): TalktoHttpResponse {
        expect($message->fresh()->overall_status)->toBe('sending');
        expect(terminalProtectionCallback($message, $callbackStatus))->toMatchArray(['accepted' => true, 'status' => 'applied']);
        $snapshot = terminalProtectionSnapshot($message->fresh());
        if ($outcome === 'timeout') {
            throw new RuntimeException('Connection timed out after destination completion.');
        }

        return new TalktoHttpResponse((int) $outcome, 'late transport failure body');
    });

    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));

    expect($message->fresh()->overall_status)->toBe('completed')
        ->and($message->fresh()->destination_action_status)->toBe($callbackStatus)
        ->and(terminalProtectionSnapshot($message->fresh()))->toBe($snapshot)
        ->and(TalktoDeadLetter::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->where('event_type', 'result_callback_applied')->count())->toBe(1)
        ->and(TalktoEvent::query()->whereIn('event_type', ['message_send_failed', 'retry_scheduled', 'retry_exhausted'])->count())->toBe(0);
    terminalProtectionAssertIgnoredAttempt($message, $outcome, 'completed');
})->with(['succeeded', 'skipped'])->with(['500', '503', '400', 'timeout']);

test('successful callback after transport failure retains existing callback field semantics', function (string $outcome): void {
    $message = terminalProtectionMessage();
    terminalProtectionSend($message, $outcome);
    $beforeCallback = $message->fresh();
    $attempt = TalktoAttempt::query()->sole();
    $attemptSnapshot = $attempt->getRawOriginal();
    expect($beforeCallback->overall_status)->toBe($outcome === '400' ? 'failed_final' : 'failed_retryable');

    expect(terminalProtectionCallback($message, 'succeeded'))->toMatchArray(['accepted' => true, 'status' => 'applied']);
    $message->refresh();
    expect($message->overall_status)->toBe('completed')
        ->and($message->destination_action_status)->toBe('succeeded')
        ->and($message->completed_at)->not->toBeNull()
        ->and($message->failed_at)->toBeNull()
        ->and($message->last_error)->toBeNull()
        ->and($message->transport_status)->toBe($beforeCallback->transport_status)
        ->and($message->retry_count)->toBe($beforeCallback->retry_count)
        ->and($message->getRawOriginal('next_retry_at'))->toBe($beforeCallback->getRawOriginal('next_retry_at'))
        ->and($message->getRawOriginal('next_attempt_at'))->toBe($beforeCallback->getRawOriginal('next_attempt_at'))
        ->and($attempt->fresh()->getRawOriginal())->toBe($attemptSnapshot)
        ->and(TalktoAttempt::query()->count())->toBe(1);

    $calls = 0;
    $client = new TerminalProtectionHttpClient(function () use (&$calls): TalktoHttpResponse {
        $calls++;

        return new TalktoHttpResponse(503, 'must not execute completed operation');
    });
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
    expect($calls)->toBe(0)->and(TalktoAttempt::query()->count())->toBe(1);
})->with(['503', '400', 'timeout']);

test('duplicate success and stale failed callbacks retain the finalized message', function (): void {
    $message = terminalProtectionMessage();
    terminalProtectionCallback($message, 'succeeded');
    $snapshot = terminalProtectionSnapshot($message->fresh());
    expect(terminalProtectionCallback($message, 'succeeded'))->toMatchArray(['accepted' => true, 'status' => 'duplicate', 'duplicate' => true]);
    expect(terminalProtectionCallback($message, 'failed_retryable'))->toMatchArray(['accepted' => true, 'status' => 'stale_ignored']);
    expect(terminalProtectionCallback($message, 'failed_final'))->toMatchArray(['accepted' => true, 'status' => 'stale_ignored']);
    expect(terminalProtectionCallback($message, 'skipped'))->toMatchArray(['accepted' => true, 'status' => 'stale_ignored']);
    expect(terminalProtectionSnapshot($message->fresh()))->toBe($snapshot)
        ->and(TalktoEvent::query()->where('event_type', 'result_callback_applied')->count())->toBe(1)
        ->and(TalktoEvent::query()->where('event_type', 'result_callback_duplicate')->count())->toBe(1)
        ->and(TalktoEvent::query()->where('event_type', 'result_callback_stale_ignored')->count())->toBe(3)
        ->and(TalktoAttempt::query()->count())->toBe(0);
});

test('nonfinalized transport failures keep retry and final failure policy', function (string $outcome, bool $retryable): void {
    $message = terminalProtectionMessage();
    terminalProtectionSend($message, $outcome);
    $message->refresh();
    $attempt = TalktoAttempt::query()->sole();
    expect($message->overall_status)->toBe($retryable ? 'failed_retryable' : 'failed_final')
        ->and($message->transport_status)->toBe($retryable ? 'failed' : 'failed_final')
        ->and((int) $message->retry_count)->toBe($retryable ? 1 : 0)
        ->and($attempt->status)->toBe($message->overall_status)
        ->and($attempt->meta['response_ignored'] ?? false)->toBeFalse()
        ->and(TalktoDeadLetter::query()->count())->toBe($retryable ? 0 : 1)
        ->and(TalktoEvent::query()->where('event_type', 'message_send_failed')->count())->toBe(1)
        ->and(TalktoEvent::query()->where('event_type', 'message_send_response_ignored')->count())->toBe(0);
    if ($retryable) {
        expect($message->next_retry_at->equalTo(now()->addSeconds(17)))->toBeTrue()
            ->and($message->next_attempt_at->equalTo($message->next_retry_at))->toBeTrue()
            ->and(TalktoEvent::query()->where('event_type', 'retry_scheduled')->count())->toBe(1);
    } else {
        expect($message->next_retry_at)->toBeNull()
            ->and($message->next_attempt_at)->toBeNull();
    }
})->with([
    'HTTP500' => ['500', true], 'HTTP503' => ['503', true], 'HTTP429' => ['429', true],
    'HTTP400' => ['400', false], 'HTTP422' => ['422', false],
    'timeout' => ['timeout', true], 'refused' => ['refused', true], 'DNS' => ['dns', true],
]);

test('disabled or exhausted retries retain existing dead letter behavior', function (string $configuration): void {
    $attributes = [];
    if ($configuration === 'disabled') {
        config(['talkto.retry.enabled' => false]);
    } elseif ($configuration === 'outgoing_disabled') {
        config(['talkto.retry.outgoing_enabled' => false]);
    } elseif ($configuration === 'custom_maximum') {
        $attributes['max_attempts'] = 1;
    } else {
        $attributes = ['retry_count' => 4, 'max_attempts' => 5];
    }
    $message = terminalProtectionMessage($attributes);
    terminalProtectionSend($message, '503');
    expect($message->fresh()->overall_status)->toBe('failed_final')
        ->and($message->fresh()->next_retry_at)->toBeNull()
        ->and((int) $message->fresh()->retry_count)->toBe($attributes['retry_count'] ?? 0)
        ->and(TalktoDeadLetter::query()->count())->toBe(1)
        ->and(TalktoAttempt::query()->sole()->status)->toBe('failed_final');
})->with(['disabled', 'outgoing_disabled', 'custom_maximum', 'exhausted']);

test('custom retry policy is still called for genuine failures and never for ignored failures', function (bool $complete): void {
    $message = terminalProtectionMessage();
    $policy = new TerminalProtectionRetryPolicy;
    $client = new TerminalProtectionHttpClient(function () use ($message, $complete): TalktoHttpResponse {
        if ($complete) {
            terminalProtectionCallback($message, 'succeeded');
        }

        return new TalktoHttpResponse(418, 'custom retryable response');
    });
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class), $policy);
    expect($policy->httpChecks)->toBe($complete ? 0 : 1)
        ->and($policy->scheduleChecks)->toBe($complete ? 0 : 1)
        ->and($message->fresh()->overall_status)->toBe($complete ? 'completed' : 'failed_retryable');
    if (! $complete) {
        expect($message->fresh()->next_retry_at->equalTo(now()->addSeconds(73)))->toBeTrue();
    }
})->with([false, true]);

test('sent and destination received states retain ordinary failure handling', function (string $state): void {
    $message = terminalProtectionMessage();
    $client = new TerminalProtectionHttpClient(function () use ($message, $state): TalktoHttpResponse {
        $message->fresh()->forceFill(['overall_status' => $state])->save();

        return new TalktoHttpResponse(503, 'transport failure without business success');
    });
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
    expect($message->fresh()->overall_status)->toBe('failed_retryable')
        ->and(TalktoEvent::query()->where('event_type', 'retry_scheduled')->count())->toBe(1);
})->with(['sent', 'destination_received', 'sending', 'failed_retryable']);

test('a second outgoing job cannot send again while the first request completes', function (): void {
    $message = terminalProtectionMessage();
    $secondCalls = 0;
    $secondClient = new TerminalProtectionHttpClient(function () use (&$secondCalls): TalktoHttpResponse {
        $secondCalls++;

        return new TalktoHttpResponse(500, 'duplicate job must not reach HTTP');
    });
    $firstClient = new TerminalProtectionHttpClient(function () use ($message, $secondClient): TalktoHttpResponse {
        (new SendOutgoingTalktoMessagePipeline($secondClient))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
        terminalProtectionCallback($message, 'succeeded');
        (new SendOutgoingTalktoMessagePipeline($secondClient))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));

        return new TalktoHttpResponse(503, 'late first-job failure');
    });
    (new SendOutgoingTalktoMessagePipeline($firstClient))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
    expect($secondCalls)->toBe(0)
        ->and($message->fresh()->overall_status)->toBe('completed')
        ->and((int) $message->fresh()->attempts)->toBe(1)
        ->and(TalktoAttempt::query()->count())->toBe(1);
});

test('custom configured model and database connections preserve ignored history atomically', function (bool $customModels): void {
    config(['database.connections.terminal_custom' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ], 'talkto.database.connection' => 'terminal_custom']);
    DB::purge('terminal_custom');
    $migrations = glob(__DIR__.'/../../database/migrations/*.php');
    sort($migrations);
    foreach ($migrations as $migration) {
        (require $migration)->up();
    }
    if ($customModels) {
        config([
            'talkto.models.message' => TerminalProtectionCustomMessage::class,
            'talkto.models.attempt' => TerminalProtectionCustomAttempt::class,
            'talkto.models.event' => TerminalProtectionCustomEvent::class,
        ]);
    }
    $messageClass = app(TalktoModelResolver::class)->message();
    $attemptClass = app(TalktoModelResolver::class)->attempt();
    $eventClass = app(TalktoModelResolver::class)->event();
    $writes = [];
    $eventClass::creating(function (TalktoEvent $event) use (&$writes): void {
        if ($event->event_type === 'message_send_response_ignored') {
            $writes[] = [$event->getConnection()->getName(), $event->getConnection()->transactionLevel(), DB::connection('sqlite')->transactionLevel()];
        }
    });
    $message = terminalProtectionMessage();
    $client = new TerminalProtectionHttpClient(function () use ($message): TalktoHttpResponse {
        terminalProtectionCallback($message, 'succeeded');

        return new TalktoHttpResponse(503, 'late custom-model response');
    });
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
    expect($message->fresh())->toBeInstanceOf($messageClass)
        ->and($message->fresh()->overall_status)->toBe('completed')
        ->and($attemptClass::query()->sole())->toBeInstanceOf($attemptClass)
        ->and($attemptClass::query()->sole()->meta['response_ignored'])->toBeTrue()
        ->and($eventClass::query()->where('event_type', 'message_send_response_ignored')->sole())->toBeInstanceOf($eventClass)
        ->and($writes)->toHaveCount(1)
        ->and($writes[0][0])->toBe('terminal_custom')
        ->and($writes[0][1])->toBeGreaterThan(0)
        ->and($writes[0][2])->toBe(0)
        ->and(DB::connection('sqlite')->table('talkto_messages')->count())->toBe(0)
        ->and(DB::connection('sqlite')->table('talkto_attempts')->count())->toBe(0)
        ->and(DB::connection('terminal_custom')->table('talkto_dead_letters')->count())->toBe(0);
})->with([false, true]);

test('inspection trace reporting and panel queries show completion and actual failed attempt together', function (): void {
    $message = terminalProtectionMessage();
    $client = new TerminalProtectionHttpClient(function () use ($message): TalktoHttpResponse {
        terminalProtectionCallback($message, 'succeeded');

        return new TalktoHttpResponse(503, 'historical failed transport');
    });
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
    $panel = app(TalktoPanelMessageQuery::class);
    $trace = app(TalktoTraceReporter::class)->traceByMessageId($message->message_id)->toArray();
    expect($panel->findMessage($message->id)->overall_status)->toBe('completed')
        ->and($panel->latest(10)->sole()->overall_status)->toBe('completed')
        ->and($panel->attemptsFor($message)->sole()->status)->toBe('failed')
        ->and($panel->eventsFor($message)->where('event_type', 'message_send_response_ignored'))->toHaveCount(1)
        ->and(app(TalktoCallbackStatusInspector::class)->inspect($message)['state'])->toBe('callback_applied')
        ->and($trace['anchor_message']['overall_status'])->toBe('completed')
        ->and($trace['attempts'])->toHaveCount(1)
        ->and($trace['attempts'][0]['status'])->toBe('failed');
    expect(Artisan::call('talkto:trace', ['message_id' => $message->message_id, '--json' => true]))->toBe(0);
    expect(Artisan::output())->toContain('completed', 'message_send_response_ignored');
});

test('late invalid envelope outcome preserves an already committed callback', function (): void {
    $message = terminalProtectionMessage();
    $snapshot = null;
    $builder = new TerminalProtectionInvalidBuilder(function () use ($message, &$snapshot): void {
        expect(terminalProtectionCallback($message, 'succeeded')['accepted'])->toBeTrue();
        $snapshot = terminalProtectionSnapshot($message->fresh());
    });
    $client = new TerminalProtectionHttpClient(fn () => throw new RuntimeException('HTTP must not be reached.'));
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, $builder);
    $attempt = TalktoAttempt::query()->sole();
    expect(terminalProtectionSnapshot($message->fresh()))->toBe($snapshot)
        ->and($attempt->status)->toBe('failed_final')
        ->and($attempt->error_class)->toBe('invalid_envelope_field')
        ->and($attempt->meta)->toMatchArray([
            'response_ignored' => true, 'reason' => 'message_already_terminal',
            'failure_reason' => 'control_character', 'field_name' => 'command', 'review_required' => true,
        ])->and(TalktoDeadLetter::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->where('event_type', 'message_send_failed')->count())->toBe(0)
        ->and(TalktoEvent::query()->where('event_type', 'message_send_response_ignored')->count())->toBe(1);
});

test('late stored payload integrity outcome preserves authoritative completion', function (): void {
    config(['talkto.models.message' => TerminalProtectionPayloadRaceMessage::class]);
    $message = terminalProtectionMessage(['payload_hash' => str_repeat('0', 64)]);
    $snapshot = null;
    TerminalProtectionPayloadRaceMessage::$duringPayloadRead = function () use ($message, &$snapshot): void {
        $message->fresh()->forceFill([
            'overall_status' => 'completed', 'destination_action_status' => 'succeeded', 'completed_at' => now(),
        ])->save();
        $snapshot = terminalProtectionSnapshot($message->fresh());
    };
    $client = new TerminalProtectionHttpClient(fn () => throw new RuntimeException('HTTP must not be reached.'));
    try {
        (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
    } finally {
        TerminalProtectionPayloadRaceMessage::$duringPayloadRead = null;
    }
    $attempt = TalktoAttempt::query()->sole();
    expect(terminalProtectionSnapshot($message->fresh()))->toBe($snapshot)
        ->and($attempt->status)->toBe('failed_final')
        ->and($attempt->error_class)->toBe('stored_payload_hash_mismatch')
        ->and($attempt->meta['response_ignored'])->toBeTrue()
        ->and(TalktoDeadLetter::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->where('event_type', 'retry_not_scheduled')->count())->toBe(0)
        ->and(TalktoEvent::query()->where('event_type', 'message_send_response_ignored')->count())->toBe(1);
});

test('existing pre-send invalid field guard does not create an attempt after completion', function (): void {
    $message = terminalProtectionMessage();
    $validator = Mockery::mock(TalktoEnvelopeFieldValidator::class)->makePartial();
    $validator->shouldReceive('validateIdentifiers')->once()->andReturnUsing(function () use ($message): void {
        $message->fresh()->forceFill(['overall_status' => 'completed', 'destination_action_status' => 'succeeded', 'completed_at' => now()])->save();
        throw TalktoInvalidEnvelopeFieldException::forControlCharacter('command');
    });
    app()->instance(TalktoEnvelopeFieldValidator::class, $validator);
    $client = new TerminalProtectionHttpClient(fn () => throw new RuntimeException('HTTP must not be reached.'));
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
    expect($message->fresh()->overall_status)->toBe('completed')
        ->and(TalktoAttempt::query()->count())->toBe(0)
        ->and(TalktoEvent::query()->count())->toBe(0)
        ->and(TalktoDeadLetter::query()->count())->toBe(0);
});

test('ignored errors and response excerpts retain existing truncation boundaries', function (bool $exception): void {
    $message = terminalProtectionMessage();
    $client = new TerminalProtectionHttpClient(function () use ($message, $exception): TalktoHttpResponse {
        terminalProtectionCallback($message, 'succeeded');
        if ($exception) {
            throw new RuntimeException(str_repeat('x', 3000));
        }

        return new TalktoHttpResponse(500, str_repeat('r', 3000));
    });
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
    $attempt = TalktoAttempt::query()->sole();
    expect($message->fresh()->overall_status)->toBe('completed')
        ->and($attempt->status)->toBe('failed')
        ->and(mb_strlen($exception ? $attempt->error_message : $attempt->response_excerpt))->toBe(2000)
        ->and(TalktoAttempt::query()->count())->toBe(1);
})->with([false, true]);

function terminalProtectionMessage(array $attributes = []): TalktoMessage
{
    $payload = ['operation' => 'terminal-protection'];

    $messageClass = app(TalktoModelResolver::class)->message();

    return $messageClass::query()->create(array_merge([
        'message_id' => 'terminal-'.bin2hex(random_bytes(8)),
        'direction' => 'outgoing',
        'source_service' => 'sender',
        'target_service' => 'peer',
        'command' => 'domain.command',
        'business_key' => 'terminal-business',
        'idempotency_key' => 'terminal-'.bin2hex(random_bytes(8)),
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'source_action_status' => 'succeeded_assumed',
        'transport_status' => 'pending',
        'overall_status' => 'waiting_to_send',
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 5,
    ], $attributes));
}

function terminalProtectionSnapshot(TalktoMessage $message): array
{
    return $message->getRawOriginal();
}

function terminalProtectionAssertIgnoredAttempt(TalktoMessage $message, string $outcome, string $terminal): void
{
    $attempt = TalktoAttempt::query()->where('message_id', $message->message_id)->sole();
    $event = TalktoEvent::query()->where('message_id', $message->message_id)->where('event_type', 'message_send_response_ignored')->sole();
    expect($attempt->status)->toBe('failed')
        ->and($attempt->stage)->toBe('transport')
        ->and((int) $attempt->attempt_no)->toBe(1)
        ->and($attempt->http_status)->toBe($outcome === 'timeout' ? null : (int) $outcome)
        ->and($attempt->error_class)->toBe($outcome === 'timeout' ? RuntimeException::class : 'http_error')
        ->and($attempt->error_message)->toBe($outcome === 'timeout' ? 'Connection timed out after destination completion.' : 'HTTP transport failed with status ['.$outcome.'].')
        ->and($attempt->response_excerpt)->toBe($outcome === 'timeout' ? null : 'late transport failure body')
        ->and($attempt->meta)->toMatchArray([
            'source' => 'sender', 'target' => 'peer', 'command' => 'domain.command',
            'business_key' => 'terminal-business', 'response_ignored' => true,
            'reason' => 'message_already_terminal', 'current_status' => $terminal,
        ])->and($attempt->meta['idempotency_key'])->toBe($message->idempotency_key)
        ->and($event->old_status)->toBe($terminal)
        ->and($event->new_status)->toBe($terminal)
        ->and($event->meta['reason'])->toBe('message_already_terminal');
}

function terminalProtectionSend(TalktoMessage $message, string $outcome): void
{
    $client = new TerminalProtectionHttpClient(function () use ($outcome): TalktoHttpResponse {
        if (in_array($outcome, ['timeout', 'refused', 'dns'], true)) {
            throw new ConnectionException('Synthetic '.$outcome.' transport failure.');
        }

        return new TalktoHttpResponse((int) $outcome, 'ordinary failure');
    });
    (new SendOutgoingTalktoMessagePipeline($client))->send($message->id, app(TalktoOutgoingEnvelopeBuilder::class));
}

function terminalProtectionCallback(TalktoMessage $message, string $status): array
{
    $result = match ($status) {
        'succeeded' => TalktoIncomingCommandResult::succeeded(['processed' => true]),
        'skipped' => TalktoIncomingCommandResult::skipped('already satisfied'),
        'failed_retryable' => TalktoIncomingCommandResult::failedRetryable('late business failure'),
        'failed_final' => TalktoIncomingCommandResult::failedFinal('late final business failure'),
    };
    $service = config('talkto.service');
    config(['talkto.service' => 'peer']);
    try {
        $envelope = TalktoResultCallbackData::fromIncomingMessageResult($message, $result, [
            'callback_message_id' => 'callback-'.bin2hex(random_bytes(8)),
        ])->toEnvelope();
        $headers = app(TalktoResultCallbackEnvelopeBuilder::class)->buildHeaders($envelope);
    } finally {
        config(['talkto.service' => $service]);
    }

    return app(ResultCallbackReceiverContract::class)->receiveResult($envelope, $headers);
}

class TerminalProtectionHttpClient implements TalktoHttpClient
{
    public function __construct(private readonly Closure $callback) {}

    public function post(string $url, array $headers, array $envelope, int $timeout): TalktoHttpResponse
    {
        return ($this->callback)();
    }
}

class TerminalProtectionRetryPolicy extends TalktoRetryPolicy
{
    public int $httpChecks = 0;

    public int $scheduleChecks = 0;

    public function isRetryableHttpStatus(?int $status, ?TalktoMessage $message = null): bool
    {
        $this->httpChecks++;

        return $status === 418;
    }

    public function canScheduleRetry(TalktoMessage $message): bool
    {
        $this->scheduleChecks++;

        return parent::canScheduleRetry($message);
    }

    public function backoffSeconds(int $retryCount, ?TalktoMessage $message = null): int
    {
        return 73;
    }
}

class TerminalProtectionCustomMessage extends TalktoMessage
{
    protected $connection = 'terminal_custom';
}

class TerminalProtectionCustomAttempt extends TalktoAttempt
{
    protected $connection = 'terminal_custom';
}

class TerminalProtectionCustomEvent extends TalktoEvent
{
    protected $connection = 'terminal_custom';
}

class TerminalProtectionInvalidBuilder extends TalktoOutgoingEnvelopeBuilder
{
    public function __construct(private readonly Closure $beforeFailure) {}

    public function endpointFor(Model $message): string
    {
        return 'https://peer.test';
    }

    public function buildEnvelope(Model $message): array
    {
        ($this->beforeFailure)();

        throw TalktoInvalidEnvelopeFieldException::forControlCharacter('command');
    }
}

class TerminalProtectionPayloadRaceMessage extends TalktoMessage
{
    public static ?Closure $duringPayloadRead = null;

    public function getPayloadAttribute(mixed $value): array
    {
        $callback = self::$duringPayloadRead;
        self::$duringPayloadRead = null;
        if ($callback !== null) {
            $callback();
        }

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }
}
