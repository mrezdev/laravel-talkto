<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;

beforeEach(function (): void {
    $this->bootPhase8PanelApp = function (array $env = []): void {
        p8PanelUseEnv(array_merge(['TALKTO_PANEL_ENABLED' => 'true'], $env));

        $this->refreshApplication();
        View::replaceNamespace('talkto', __DIR__.'/../../resources/views');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        expect($this->artisan('migrate')->run())->toBe(0);

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'talkto.panel.authorization.enabled' => false,
            'talkto.service' => 'panel-test',
            'talkto.outgoing.target-alpha' => [
                'url' => 'https://target-alpha.test',
                'secret' => 'outgoing-secret',
                'endpoint' => '/api/talkto/receive',
            ],
            'talkto.incoming.source-alpha' => [
                'secret' => 'incoming-secret',
                'allowed_commands' => [
                    'website.event-created' => ['driver' => 'handler'],
                ],
            ],
        ]);

        $this->withoutMiddleware();
    };
});

afterEach(function (): void {
    p8PanelClearEnv();
});

test('message filters render finite selects datetime controls and preserve values', function (): void {
    ($this->bootPhase8PanelApp)();

    p8PanelMessage('phase8-filter-out', 'outgoing', 'completed', [
        'target_service' => 'target-alpha',
        'command' => 'domain.sync',
        'created_at' => Carbon::parse('2026-06-20 12:15:00'),
        'updated_at' => Carbon::parse('2026-06-20 12:15:00'),
    ]);

    $this->get('/talkto/messages?direction=outgoing&status=completed&completion_state=completed&createdFrom=2026-06-20T10:00&createdTo=2026-06-21T10:00&messageId=phase8&command=domain.sync')
        ->assertOk()
        ->assertSee('<select name="direction"', false)
        ->assertSee('<option value="outgoing" selected>outgoing</option>', false)
        ->assertSee('<select name="status"', false)
        ->assertSee('<option value="completed" selected>completed</option>', false)
        ->assertSee('Completion state')
        ->assertSee('<select name="completion_state"', false)
        ->assertSee('<option value="">All</option>', false)
        ->assertSee('<option value="completed" selected>Completed</option>', false)
        ->assertSee('<option value="not_completed" >Not completed</option>', false)
        ->assertSee('<input type="datetime-local" name="createdFrom" value="2026-06-20T10:00"', false)
        ->assertSee('<input type="datetime-local" name="createdTo" value="2026-06-21T10:00"', false)
        ->assertSee('<input type="text" name="messageId" value="phase8"', false)
        ->assertSee('<input type="text" name="command" value="domain.sync"', false)
        ->assertSee('Clear')
        ->assertSee('phase8-filter-out');
});

test('message filters ignore invalid finite and datetime values', function (): void {
    ($this->bootPhase8PanelApp)();

    p8PanelMessage('phase8-invalid-filter', 'outgoing', 'completed');

    $this->getJson('/talkto/messages?direction=sideways&status=nope&completion_state=invalid&createdFrom=not-a-date&createdTo=also-nope')
        ->assertOk()
        ->assertJsonPath('filters.direction', null)
        ->assertJsonPath('filters.status', null)
        ->assertJsonPath('filters.completion_state', null)
        ->assertJsonPath('filters.created_from', null)
        ->assertJsonPath('filters.created_to', null)
        ->assertJsonPath('messages.total', 1);
});

test('message filters still apply direction status date range and text filters', function (): void {
    ($this->bootPhase8PanelApp)();

    p8PanelMessage('phase8-filter-out', 'outgoing', 'completed', [
        'target_service' => 'target-alpha',
        'command' => 'domain.sync',
        'created_at' => Carbon::parse('2026-06-20 12:15:00'),
        'updated_at' => Carbon::parse('2026-06-20 12:15:00'),
    ]);
    p8PanelMessage('phase8-filter-in', 'incoming', 'failed_retryable', [
        'source_service' => 'source-alpha',
        'command' => 'website.event-created',
        'created_at' => Carbon::parse('2026-06-19 12:15:00'),
        'updated_at' => Carbon::parse('2026-06-19 12:15:00'),
    ]);

    $this->getJson('/talkto/messages?direction=outgoing&status=completed&completion_state=completed&createdFrom=2026-06-20T00:00&createdTo=2026-06-20T23:59&messageId=phase8-filter-out')
        ->assertOk()
        ->assertJsonPath('filters.direction', 'outgoing')
        ->assertJsonPath('filters.status', 'completed')
        ->assertJsonPath('filters.completion_state', 'completed')
        ->assertJsonPath('filters.created_from', '2026-06-20 00:00:00')
        ->assertJsonPath('filters.created_to', '2026-06-20 23:59:00')
        ->assertJsonPath('messages.total', 1)
        ->assertJsonPath('messages.data.0.message_id', 'phase8-filter-out');
});

test('completion state filter persists through pagination and clear link resets it', function (): void {
    ($this->bootPhase8PanelApp)();

    config(['talkto.panel.messages.per_page' => 1]);

    p8PanelMessage('phase8-page-completed', 'outgoing', 'completed', [
        'created_at' => Carbon::parse('2026-06-22 12:00:00'),
        'updated_at' => Carbon::parse('2026-06-22 12:00:00'),
    ]);
    p8PanelMessage('phase8-page-pending', 'outgoing', 'waiting_to_send', [
        'created_at' => Carbon::parse('2026-06-21 12:00:00'),
        'updated_at' => Carbon::parse('2026-06-21 12:00:00'),
    ]);
    p8PanelMessage('phase8-page-final', 'outgoing', 'failed_final', [
        'created_at' => Carbon::parse('2026-06-20 12:00:00'),
        'updated_at' => Carbon::parse('2026-06-20 12:00:00'),
    ]);

    $this->get('/talkto/messages?completion_state=not_completed&page=2')
        ->assertOk()
        ->assertSee('<option value="not_completed" selected>Not completed</option>', false)
        ->assertSee('phase8-page-final')
        ->assertDontSee('phase8-page-completed')
        ->assertSee('completion_state=not_completed', false)
        ->assertSee('Clear')
        ->assertDontSee('name="page"', false);
});

function p8PanelUseEnv(array $values = []): void
{
    p8PanelClearEnv();

    foreach ($values as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function p8PanelClearEnv(): void
{
    foreach ([
        'TALKTO_PANEL_ENABLED',
        'TALKTO_PANEL_PREFIX',
        'TALKTO_PANEL_DOMAIN',
        'TALKTO_PANEL_ROUTE_NAME',
        'TALKTO_PANEL_TAILWIND_CDN',
        'TALKTO_ROUTES_ENABLED',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
}

function p8PanelMessage(string $messageId, string $direction, string $status, array $attributes = []): TalktoMessage
{
    $createdAt = $attributes['created_at'] ?? now();

    $message = TalktoMessage::query()->create(array_merge([
        'message_id' => $messageId,
        'correlation_id' => 'corr-'.$messageId,
        'direction' => $direction,
        'source_service' => $direction === 'incoming' ? 'source-alpha' : 'panel-test',
        'target_service' => $direction === 'outgoing' ? 'target-alpha' : 'panel-test',
        'command' => 'domain.sync',
        'payload' => ['id' => $messageId],
        'payload_hash' => 'hash-'.$messageId,
        'schema_version' => 1,
        'source_action_status' => $direction === 'outgoing' ? $status : null,
        'transport_status' => $direction === 'outgoing' ? $status : null,
        'destination_receive_status' => $direction === 'incoming' ? 'received' : null,
        'destination_action_status' => $direction === 'incoming' ? $status : null,
        'overall_status' => $status,
        'attempts' => 0,
        'retry_count' => 0,
        'max_attempts' => 3,
        'completed_at' => in_array($status, ['completed', 'succeeded'], true) ? $createdAt : null,
        'failed_at' => str_starts_with($status, 'failed') ? $createdAt : null,
        'last_response' => null,
    ], array_diff_key($attributes, array_flip(['created_at', 'updated_at']))));

    $message->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $attributes['updated_at'] ?? $createdAt,
    ])->save();

    return $message->fresh();
}
