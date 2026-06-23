<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoPayloadHasher;
use Mrezdev\LaravelTalkto\Services\TalktoSecurityAuditor;
use Mrezdev\LaravelTalkto\Services\TalktoTraceReporter;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    expect($this->artisan('migrate')->run())->toBe(0);

    config([
        'talkto.service' => 'target-service',
        'talkto.security.require_signature' => true,
        'talkto.security.require_timestamp' => true,
        'talkto.security.signature_version' => 'v1',
        'talkto.security.accept_versions' => ['v1', 'v2'],
        'talkto.security.timestamp_tolerance_seconds' => 300,
        'talkto.security.replay_protection.enabled' => true,
        'talkto.security.replay_protection.require_nonce_for_v2' => false,
        'talkto.security.redacted_keys' => ['custom_credential'],
        'talkto.routes.enabled' => false,
        'talkto.routes.middleware' => ['api'],
        'talkto.callbacks.enabled' => true,
        'talkto.callbacks.command' => 'talkto.result',
        'talkto.outgoing.source-service' => [
            'url' => 'https://source.test',
            'secret' => 'outgoing-test-shared-secret',
            'callback_endpoint' => '/callbacks/talkto',
        ],
        'talkto.incoming.source-service' => [
            'secret' => 'incoming-test-shared-secret',
            'allowed_commands' => [
                'domain.command' => [
                    'driver' => 'none',
                ],
            ],
        ],
    ]);
});

test('security redactor redacts secret-like keys recursively', function (): void {
    $redacted = app(TalktoSecurityRedactor::class)->redactValue([
        'visible' => 'safe-value',
        'api_key' => 'api-key-value',
        'nested' => [
            'token' => 'token-value',
            'private_key' => 'private-key-value',
            'custom_credential' => 'custom-value',
        ],
        'items' => [
            ['password' => 'password-value'],
        ],
    ]);

    expect($redacted['visible'])->toBe('safe-value')
        ->and($redacted['api_key'])->toBe('[redacted]')
        ->and($redacted['nested']['token'])->toBe('[redacted]')
        ->and($redacted['nested']['private_key'])->toBe('[redacted]')
        ->and($redacted['nested']['custom_credential'])->toBe('[redacted]')
        ->and($redacted['items'][0]['password'])->toBe('[redacted]');
});

test('security redactor redacts configured shared secrets from text', function (): void {
    $redactor = app(TalktoSecurityRedactor::class);

    expect($redactor->configuredSecrets())->toContain('outgoing-test-shared-secret')
        ->and($redactor->configuredSecrets())->toContain('incoming-test-shared-secret')
        ->and($redactor->redactText('failed outgoing-test-shared-secret and incoming-test-shared-secret'))->toBe('failed [redacted] and [redacted]');
});

test('security redactor redacts authorization signature nonce and cookie headers', function (): void {
    $headers = app(TalktoSecurityRedactor::class)->redactHeaders([
        'Authorization' => 'Bearer token-value',
        'X-Talkto-Signature' => 'signature-value',
        'X-Talkto-Nonce' => 'nonce-value',
        'Cookie' => 'cookie-value',
        'Set-Cookie' => 'set-cookie-value',
        'X-Visible' => 'safe-value',
    ]);

    expect($headers['Authorization'])->toBe('[redacted]')
        ->and($headers['X-Talkto-Signature'])->toBe('[redacted]')
        ->and($headers['X-Talkto-Nonce'])->toBe('[redacted]')
        ->and($headers['Cookie'])->toBe('[redacted]')
        ->and($headers['Set-Cookie'])->toBe('[redacted]')
        ->and($headers['X-Visible'])->toBe('safe-value');
});

test('security redactor redacts sensitive text patterns without leaking tail values', function (): void {
    $redactor = app(TalktoSecurityRedactor::class);

    expect($redactor->redactText('Authorization: Bearer raw-token-value'))->not->toContain('raw-token-value')
        ->and($redactor->redactText('Authorization: Bearer raw-token-value'))->toBe('Authorization: [redacted]')
        ->and($redactor->redactText('Bearer raw-token-value'))->not->toContain('raw-token-value')
        ->and($redactor->redactText('Cookie: session=abc; remember=xyz'))->not->toContain('session=abc')
        ->and($redactor->redactText('Cookie: session=abc; remember=xyz'))->not->toContain('remember=xyz')
        ->and($redactor->redactText('Set-Cookie: session=abc; HttpOnly'))->not->toContain('session=abc')
        ->and($redactor->redactText('X-Talkto-Signature: raw-signature'))->not->toContain('raw-signature')
        ->and($redactor->redactText('X-Talkto-Nonce: raw-nonce'))->not->toContain('raw-nonce')
        ->and($redactor->redactText('{"token":"raw-token","visible":"safe"}'))->not->toContain('raw-token')
        ->and($redactor->redactText('{"token":"raw-token","visible":"safe"}'))->toContain('"visible":"safe"')
        ->and($redactor->redactText('{"api_key":"raw-key"}'))->not->toContain('raw-key')
        ->and($redactor->redactText("'password' => 'raw-password'"))->not->toContain('raw-password')
        ->and($redactor->redactText('private_key = raw-private-key'))->not->toContain('raw-private-key');
});

test('trace reporter uses centralized redaction for payloads and configured secrets', function (): void {
    securityAuditMessage('security-trace-redaction', [
        'payload' => [
            'visible' => 'safe-value',
            'custom_credential' => 'custom-value',
        ],
        'last_error' => 'failed with outgoing-test-shared-secret',
    ]);
    TalktoAttempt::query()->create([
        'message_id' => 'security-trace-redaction',
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'failed',
        'request_excerpt' => 'X-Talkto-Nonce: nonce-value',
        'meta' => ['note' => 'incoming-test-shared-secret'],
    ]);

    $snapshot = app(TalktoTraceReporter::class)->traceByMessageId('security-trace-redaction', 100, true)->toArray();

    expect($snapshot['anchor_message']['payload']['visible'])->toBe('safe-value')
        ->and($snapshot['anchor_message']['payload']['custom_credential'])->toBe('[redacted]')
        ->and($snapshot['anchor_message']['last_error'])->toBe('failed with [redacted]')
        ->and($snapshot['attempts'][0]['request_excerpt'])->toBe('X-Talkto-Nonce: [redacted]')
        ->and($snapshot['attempts'][0]['meta']['note'])->toBe('[redacted]');
});

test('trace reporter redacts sensitive text excerpts fully', function (): void {
    securityAuditMessage('security-trace-text-redaction');
    TalktoAttempt::query()->create([
        'message_id' => 'security-trace-text-redaction',
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'failed',
        'request_excerpt' => "Authorization: Bearer raw-token-value\nX-Talkto-Signature: raw-signature",
        'response_excerpt' => '{"token":"raw-token","visible":"safe"}',
        'meta' => [],
    ]);

    $snapshot = app(TalktoTraceReporter::class)->traceByMessageId('security-trace-text-redaction', 100, true)->toArray();
    $attempt = $snapshot['attempts'][0];

    expect($attempt['request_excerpt'])->not->toContain('raw-token-value')
        ->and($attempt['request_excerpt'])->not->toContain('raw-signature')
        ->and($attempt['response_excerpt'])->not->toContain('raw-token')
        ->and($attempt['response_excerpt'])->toContain('"visible":"safe"');
});

test('callback sender queue failure events do not expose configured secrets', function (): void {
    config(['talkto.jobs.send_message' => SecurityFailingSecretCallbackSendJob::class]);
    Http::fake();

    $message = securityAuditIncomingMessage('security-callback-redaction');

    app(ResultCallbackSenderContract::class)->sendResult(
        $message,
        TalktoIncomingCommandResult::failedFinal('Final failure.')
    );

    $encodedEvents = TalktoEvent::query()
        ->where('message_id', 'security-callback-redaction')
        ->get()
        ->map(fn (TalktoEvent $event): string => json_encode($event->meta))
        ->implode("\n");

    expect($encodedEvents)->toContain('[redacted]')
        ->and($encodedEvents)->not->toContain('outgoing-test-shared-secret');

    Http::assertNothingSent();
});

test('callback sender queue failure excerpts redact json-like token values', function (): void {
    config(['talkto.jobs.send_message' => SecurityFailingJsonCallbackSendJob::class]);
    Http::fake();

    $message = securityAuditIncomingMessage('security-callback-json-redaction');

    app(ResultCallbackSenderContract::class)->sendResult(
        $message,
        TalktoIncomingCommandResult::failedFinal('Final failure.')
    );

    $failed = TalktoEvent::query()
        ->where('message_id', 'security-callback-json-redaction')
        ->where('event_type', 'result_callback_queue_failed')
        ->firstOrFail();

    expect($failed->meta['error_message'])->not->toContain('raw-token')
        ->and($failed->meta['error_message'])->toContain('"visible":"safe"');

    Http::assertNothingSent();
});

test('security auditor reports expected findings without exposing secrets', function (): void {
    config([
        'talkto.security.require_signature' => false,
        'talkto.security.require_timestamp' => false,
        'talkto.security.signature_version' => 'v9',
        'talkto.security.accept_versions' => ['v1', 'v2', 'v9'],
        'talkto.security.timestamp_tolerance_seconds' => 900,
        'talkto.security.replay_protection.enabled' => false,
        'talkto.security.replay_protection.require_nonce_for_v2' => false,
        'talkto.routes.enabled' => true,
        'talkto.routes.middleware' => ['api'],
        'talkto.outgoing.peer-a' => [
            'url' => 'https://peer-a.test',
            'secret' => '',
            'headers' => [
                'Authorization' => 'Bearer header-token',
                'X-Visible' => 'safe-value',
            ],
        ],
        'talkto.incoming.peer-a' => [
            'secret' => '',
        ],
    ]);

    $audit = app(TalktoSecurityAuditor::class)->audit()->toArray();
    $codes = array_column($audit['findings'], 'code');
    $encoded = json_encode($audit);

    expect($codes)->toContain('signatures_disabled')
        ->and($codes)->toContain('unsigned_timestamp_disabled')
        ->and($codes)->toContain('invalid_signature_version')
        ->and($codes)->toContain('accepts_v1_signatures')
        ->and($codes)->toContain('accepts_v1_v2_signatures')
        ->and($codes)->toContain('timestamp_tolerance_high')
        ->and($codes)->toContain('replay_protection_disabled')
        ->and($codes)->toContain('v2_nonce_not_required')
        ->and($codes)->toContain('routes_without_throttle')
        ->and($codes)->toContain('outgoing_target_missing_secret')
        ->and($codes)->toContain('incoming_source_missing_allowed_commands')
        ->and($encoded)->toContain('[redacted]')
        ->and($encoded)->not->toContain('header-token');
});

test('security auditor accepts outgoing receive url and callback url config', function (): void {
    config(['talkto.outgoing' => [
        'phase2-peer' => [
            'receive_url' => 'https://phase2-peer.test/api/talkto/receive',
            'callback_url' => 'https://phase2-peer.test/api/talkto/callback',
            'secret' => 'phase2-outgoing-shared-secret',
        ],
    ]]);

    $findings = app(TalktoSecurityAuditor::class)->audit()->toArray()['findings'];

    expect(securityAuditFindingsForTarget($findings, 'phase2-peer', 'outgoing_target_missing_url'))->toBe([])
        ->and(securityAuditFindingsForTarget($findings, 'phase2-peer', 'outgoing_target_missing_secret'))->toBe([]);
});

test('security auditor accepts outgoing base url with receive and callback endpoints', function (): void {
    config(['talkto.outgoing' => [
        'phase2-peer' => [
            'base_url' => 'https://phase2-peer.test/root',
            'receive_endpoint' => '/talkto/receive',
            'callback_endpoint' => '/talkto/callback',
            'secret' => 'phase2-outgoing-shared-secret',
        ],
    ]]);

    $findings = app(TalktoSecurityAuditor::class)->audit()->toArray()['findings'];

    expect(securityAuditFindingsForTarget($findings, 'phase2-peer', 'outgoing_target_missing_url'))->toBe([])
        ->and(securityAuditFindingsForTarget($findings, 'phase2-peer', 'outgoing_target_missing_secret'))->toBe([]);
});

test('security auditor accepts outgoing url and endpoint aliases', function (): void {
    config(['talkto.outgoing' => [
        'phase2-peer' => [
            'url' => 'https://phase2-peer.test/root',
            'endpoint' => '/talkto/receive',
            'secret' => 'phase2-outgoing-shared-secret',
        ],
    ]]);

    $findings = app(TalktoSecurityAuditor::class)->audit()->toArray()['findings'];

    expect(securityAuditFindingsForTarget($findings, 'phase2-peer', 'outgoing_target_missing_url'))->toBe([]);
});

test('security auditor reports missing receive url for unknown url keys', function (): void {
    config(['talkto.outgoing' => [
        'phase2-peer' => [
            'unsupported_url' => 'https://phase2-peer.test/api/talkto/receive',
            'secret' => 'phase2-outgoing-shared-secret',
        ],
    ]]);

    $findings = app(TalktoSecurityAuditor::class)->audit()->toArray()['findings'];
    $missing = securityAuditFindingsForTarget($findings, 'phase2-peer', 'outgoing_target_missing_url');

    expect($missing)->not->toBeEmpty()
        ->and($missing[0]['recommendation'])->toContain('receive_url')
        ->and($missing[0]['recommendation'])->toContain('base_url');
});

test('security auditor accepts outgoing signing secret alias', function (): void {
    config(['talkto.outgoing' => [
        'phase2-peer' => [
            'base_url' => 'https://phase2-peer.test',
            'receive_endpoint' => '/api/talkto/receive',
            'signing_secret' => 'phase2-outgoing-shared-secret',
        ],
    ]]);

    $findings = app(TalktoSecurityAuditor::class)->audit()->toArray()['findings'];

    expect(securityAuditFindingsForTarget($findings, 'phase2-peer', 'outgoing_target_missing_secret'))->toBe([]);
});

test('secure v2 only config produces no critical security auditor findings', function (): void {
    config([
        'talkto.security.require_signature' => true,
        'talkto.security.require_timestamp' => true,
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.timestamp_tolerance_seconds' => 300,
        'talkto.security.replay_protection.enabled' => true,
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.routes.enabled' => false,
        'talkto.callbacks.enabled' => false,
        'talkto.outgoing' => [],
        'talkto.incoming' => [],
    ]);

    $audit = app(TalktoSecurityAuditor::class)->audit()->toArray();

    expect($audit['summary']['severity_counts']['critical'])->toBe(0)
        ->and($audit['summary']['severity_counts']['error'])->toBe(0);
});

test('security auditor treats missing v2 nonce requirement as fail safe enabled', function (): void {
    config([
        'talkto.security.require_signature' => true,
        'talkto.security.require_timestamp' => true,
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.timestamp_tolerance_seconds' => 300,
        'talkto.security.replay_protection' => [
            'enabled' => true,
        ],
        'talkto.routes.enabled' => false,
        'talkto.callbacks.enabled' => false,
        'talkto.outgoing' => [],
        'talkto.incoming' => [],
    ]);

    $codes = array_column(app(TalktoSecurityAuditor::class)->audit()->toArray()['findings'], 'code');

    expect($codes)->not->toContain('v2_nonce_not_required');
});

test('security auditor reports legacy signature and dangerous source config findings', function (): void {
    config([
        'talkto.security.signature_version' => 'v1',
        'talkto.security.accept_versions' => ['v1', 'v2'],
        'talkto.security.replay_protection.require_nonce_for_v2' => false,
        'talkto.incoming.peer-danger' => [
            'secret' => 'incoming-test-shared-secret',
            'allow_all_commands' => true,
        ],
    ]);

    $codes = array_column(app(TalktoSecurityAuditor::class)->audit()->toArray()['findings'], 'code');

    expect($codes)->toContain('outgoing_signature_v1')
        ->and($codes)->toContain('accepts_v1_signatures')
        ->and($codes)->toContain('accepts_v1_v2_signatures')
        ->and($codes)->toContain('v2_nonce_not_required')
        ->and($codes)->toContain('incoming_source_missing_allowed_commands')
        ->and($codes)->toContain('incoming_source_all_commands_allowed');
});

test('security auditor flags invalid accepted versions missing secrets and command allowlists', function (): void {
    config([
        'talkto.security.accept_versions' => [],
        'talkto.outgoing.peer-b' => [
            'url' => 'https://peer-b.test',
        ],
        'talkto.incoming.peer-b' => [
            'allowed_commands' => [],
        ],
    ]);

    $codes = array_column(app(TalktoSecurityAuditor::class)->audit()->toArray()['findings'], 'code');

    expect($codes)->toContain('invalid_accept_versions')
        ->and($codes)->toContain('outgoing_target_missing_secret')
        ->and($codes)->toContain('incoming_source_missing_secret')
        ->and($codes)->toContain('incoming_source_empty_allowed_commands');
});

test('security audit json output is stable and fail thresholds are honored', function (): void {
    config([
        'talkto.security.require_signature' => true,
        'talkto.security.accept_versions' => ['v1', 'v2'],
        'talkto.outgoing.peer-c' => [
            'url' => 'https://peer-c.test',
        ],
    ]);

    expect(Artisan::call('talkto:security-audit', ['--json' => true]))->toBe(0);

    $json = json_decode(Artisan::output(), true);

    expect($json)->toHaveKeys(['ok', 'summary', 'findings', 'checked_at'])
        ->and($json['summary'])->toHaveKeys(['ok', 'total_findings', 'severity_counts'])
        ->and(array_column($json['findings'], 'code'))->toContain('outgoing_target_missing_secret');

    expect(Artisan::call('talkto:security-audit', ['--fail-on' => 'error']))->toBe(1)
        ->and(Artisan::call('talkto:security-audit', ['--fail-on' => 'critical']))->toBe(0);
});

test('security audit command rejects invalid fail threshold', function (): void {
    expect(Artisan::call('talkto:security-audit', ['--fail-on' => 'info']))->toBe(1)
        ->and(Artisan::output())->toContain('Invalid --fail-on');
});

test('security audit command is read only', function (): void {
    securityAuditMessage('security-readonly');
    TalktoAttempt::query()->create([
        'message_id' => 'security-readonly',
        'stage' => 'transport',
        'attempt_no' => 1,
        'status' => 'sent',
    ]);
    TalktoEvent::query()->create([
        'message_id' => 'security-readonly',
        'service_name' => 'testing',
        'event_type' => 'existing_event',
    ]);
    TalktoDeadLetter::query()->create([
        'message_id' => 'security-readonly',
        'direction' => 'outgoing',
        'source' => 'source-service',
        'target' => 'target-service',
        'command' => 'domain.command',
        'payload' => ['id' => 'security-readonly'],
        'failure_reason' => 'existing failure',
        'failed_status' => 'failed_final',
        'status' => 'open',
    ]);

    $before = [
        TalktoMessage::query()->count(),
        TalktoAttempt::query()->count(),
        TalktoEvent::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    expect(Artisan::call('talkto:security-audit', ['--json' => true]))->toBe(0);

    $after = [
        TalktoMessage::query()->count(),
        TalktoAttempt::query()->count(),
        TalktoEvent::query()->count(),
        TalktoDeadLetter::query()->count(),
    ];

    expect($after)->toBe($before);
});

function securityAuditMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = $attributes['payload'] ?? ['id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'direction' => 'outgoing',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'source_action_status' => 'succeeded',
        'transport_status' => 'sent',
        'overall_status' => 'sent',
        'retry_count' => 0,
        'max_attempts' => 3,
    ], $attributes));
}

function securityAuditIncomingMessage(string $messageId, array $attributes = []): TalktoMessage
{
    $payload = $attributes['payload'] ?? ['id' => $messageId];

    return TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'correlation-'.$messageId,
        'direction' => 'incoming',
        'source_service' => 'source-service',
        'target_service' => 'target-service',
        'command' => 'domain.command',
        'payload' => $payload,
        'payload_hash' => app(TalktoPayloadHasher::class)->hash($payload),
        'schema_version' => 1,
        'destination_receive_status' => 'received',
        'destination_action_status' => 'succeeded',
        'overall_status' => 'succeeded',
        'attempts' => 1,
        'retry_count' => 0,
        'max_attempts' => 5,
        'received_at' => now(),
    ], $attributes));
}

function securityAuditFindingsForTarget(array $findings, string $target, ?string $code = null): array
{
    return array_values(array_filter(
        $findings,
        static fn (array $finding): bool => ($finding['context']['target'] ?? null) === $target
            && ($code === null || ($finding['code'] ?? null) === $code)
    ));
}

class SecurityFailingSecretCallbackSendJob extends SendTalktoMessage
{
    public static function dispatch(...$arguments): mixed
    {
        throw new RuntimeException('temporary outgoing-test-shared-secret');
    }
}

class SecurityFailingJsonCallbackSendJob extends SendTalktoMessage
{
    public static function dispatch(...$arguments): mixed
    {
        throw new RuntimeException('{"token":"raw-token","visible":"safe"}');
    }
}
