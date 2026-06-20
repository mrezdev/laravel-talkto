<?php

use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    securityAuditCommandSecureConfig();
});

test('secure config returns exit code zero', function (): void {
    expect(Artisan::call('talkto:audit-security'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('[PASS] HMAC signatures are required.')
        ->and($output)->toContain('Summary:')
        ->and($output)->not->toContain('[FAIL]');
});

test('disabled signatures produce fail and non zero exit code', function (): void {
    config(['talkto.security.require_signature' => false]);

    expect(Artisan::call('talkto:audit-security'))->toBe(1)
        ->and(Artisan::output())->toContain('[FAIL] HMAC signatures are disabled, which is unsafe.');
});

test('replay protection disabled produces fail and non zero exit code', function (): void {
    config(['talkto.security.replay_protection.enabled' => false]);

    expect(Artisan::call('talkto:audit-security'))->toBe(1)
        ->and(Artisan::output())->toContain('[FAIL] Replay protection is disabled.');
});

test('missing incoming source secret produces fail', function (): void {
    config(['talkto.incoming.inventory.secret' => '']);

    expect(Artisan::call('talkto:audit-security'))->toBe(1)
        ->and(Artisan::output())->toContain('[FAIL] Incoming source `inventory` is missing a shared secret.');
});

test('missing incoming source allowed commands produces fail', function (): void {
    config([
        'talkto.incoming.inventory' => [
            'secret' => 'incoming-inventory-secret',
        ],
    ]);

    expect(Artisan::call('talkto:audit-security'))->toBe(1)
        ->and(Artisan::output())->toContain('[FAIL] Incoming source `inventory` has no allowed_commands and does not explicitly allow all commands.');
});

test('empty incoming source allowed commands produces fail', function (): void {
    config(['talkto.incoming.inventory.allowed_commands' => []]);

    expect(Artisan::call('talkto:audit-security'))->toBe(1)
        ->and(Artisan::output())->toContain('[FAIL] Incoming source `inventory` has empty or invalid allowed_commands.');
});

test('explicit allow all commands produces warn but does not fail by itself', function (): void {
    config([
        'talkto.incoming.inventory' => [
            'secret' => 'incoming-inventory-secret',
            'allow_all_commands' => true,
        ],
    ]);

    expect(Artisan::call('talkto:audit-security'))->toBe(0)
        ->and(Artisan::output())->toContain('[WARN] Incoming source `inventory` explicitly allows all commands.')
        ->and(Artisan::output())->not->toContain('[FAIL]');
});

test('accepted v1 signature version produces warn but does not fail by itself', function (): void {
    config(['talkto.security.accept_versions' => ['v1', 'v2']]);

    expect(Artisan::call('talkto:audit-security'))->toBe(0)
        ->and(Artisan::output())->toContain('[WARN] v1 signatures are still accepted; this should be legacy/manual opt-in only.')
        ->and(Artisan::output())->not->toContain('[FAIL]');
});

test('legacy outgoing v1 signature version produces warn', function (): void {
    config(['talkto.security.signature_version' => 'v1']);

    expect(Artisan::call('talkto:audit-security'))->toBe(0)
        ->and(Artisan::output())->toContain('[WARN] Outgoing signature version is not v2; v1 is legacy/manual opt-in only.')
        ->and(Artisan::output())->not->toContain('[FAIL]');
});

test('disabled v2 nonce requirement produces warn', function (): void {
    config(['talkto.security.replay_protection.require_nonce_for_v2' => false]);

    expect(Artisan::call('talkto:audit-security'))->toBe(0)
        ->and(Artisan::output())->toContain('[WARN] v2 signatures are accepted without requiring a nonce.')
        ->and(Artisan::output())->not->toContain('[FAIL]');
});

test('missing v2 nonce requirement config is treated as enabled', function (): void {
    config(['talkto.security.replay_protection' => [
        'enabled' => true,
    ]]);

    expect(Artisan::call('talkto:audit-security'))->toBe(0)
        ->and(Artisan::output())->toContain('[PASS] v2 nonce enforcement is enabled.')
        ->and(Artisan::output())->not->toContain('[WARN] v2 signatures are accepted without requiring a nonce.');
});

test('routes enabled without throttle middleware produces warn', function (): void {
    config([
        'talkto.routes.enabled' => true,
        'talkto.routes.middleware' => ['api'],
    ]);

    expect(Artisan::call('talkto:audit-security'))->toBe(0)
        ->and(Artisan::output())->toContain('[WARN] Talkto routes are enabled without throttle or rate-limit middleware.');
});

test('panel disabled produces pass', function (): void {
    config(['talkto.panel.enabled' => false]);

    expect(Artisan::call('talkto:audit-security'))->toBe(0)
        ->and(Artisan::output())->toContain('[PASS] Talkto panel is disabled.');
});

test('json output works and includes summary and checks', function (): void {
    expect(Artisan::call('talkto:audit-security', ['--json' => true]))->toBe(0);

    $data = json_decode(Artisan::output(), true);

    expect($data)->toBeArray()
        ->and($data['ok'])->toBeTrue()
        ->and($data['summary'])->toHaveKeys(['passes', 'warnings', 'failures'])
        ->and($data['checks'])->toBeArray()
        ->and($data['checks'][0])->toHaveKeys(['status', 'key', 'message']);
});

test('json output returns valid json with no extra terminal noise', function (): void {
    expect(Artisan::call('talkto:audit-security', ['--json' => true]))->toBe(0);

    $output = trim(Artisan::output());
    $decoded = json_decode($output, true);

    expect($output)->toStartWith('{')
        ->and($output)->toEndWith('}')
        ->and(json_last_error())->toBe(JSON_ERROR_NONE)
        ->and($decoded)->toBeArray();
});

function securityAuditCommandSecureConfig(): void
{
    config([
        'talkto.security.require_signature' => true,
        'talkto.security.signature_version' => 'v2',
        'talkto.security.accept_versions' => ['v2'],
        'talkto.security.replay_protection.enabled' => true,
        'talkto.security.replay_protection.require_nonce_for_v2' => true,
        'talkto.routes.enabled' => false,
        'talkto.routes.middleware' => ['api', 'throttle:talkto'],
        'talkto.panel.enabled' => false,
        'talkto.panel.route.middleware' => ['web', 'auth'],
        'talkto.incoming' => [
            'inventory' => [
                'secret' => 'incoming-inventory-secret',
                'allowed_commands' => [
                    'orders.mark-paid',
                ],
            ],
        ],
    ]);
}
