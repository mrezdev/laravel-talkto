<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClientWithOptions;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoResultCallbackController;
use Mrezdev\LaravelTalkto\Models\TalktoAttempt;
use Mrezdev\LaravelTalkto\Models\TalktoDeadLetter;
use Mrezdev\LaravelTalkto\Models\TalktoEvent;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Models\TalktoNonce;
use Mrezdev\LaravelTalkto\Services\TalktoDoctor;

beforeEach(function (): void {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    expect($this->artisan('migrate')->run())->toBe(0);
    config([
        'talkto.service' => 'testing',
        'talkto.outgoing' => [
            'inventory' => ['base_url' => 'https://inventory.test', 'secret' => 'doctor-inventory-secret'],
            'billing' => ['receive_url' => 'https://billing.test/custom/receive', 'secret' => 'doctor-billing-secret'],
        ],
        'talkto.incoming' => [
            'handlers' => [], 'unknown_command_strategy' => 'fail',
            'inventory' => ['secret' => 'doctor-source-secret', 'allowed_commands' => [
                'stock.reserve' => ['driver' => 'none', 'idempotency' => 'required'],
            ]],
        ],
    ]);
});

test('doctor is registered with only its json option and does not eagerly resolve the inspector', function (): void {
    $command = Artisan::all()['talkto:doctor'];
    expect($command->getDefinition()->hasOption('json'))->toBeTrue()
        ->and($command->getDefinition()->getArguments())->toBe([])
        ->and(app()->resolved(TalktoDoctor::class))->toBeFalse();
    foreach (['fix', 'repair', 'remote', 'ping', 'migrate'] as $option) {
        expect($command->getDefinition()->hasOption($option))->toBeFalse();
    }
});

test('healthy doctor prints readiness and all logical categories', function (): void {
    expect(Artisan::call('talkto:doctor'))->toBe(0);
    expect(Artisan::output())->toContain('Laravel Talkto Doctor', 'Environment', 'Storage', 'Security', 'Peers', 'Runtime', 'Optional Features', 'PASS', 'INFO', 'Talkto is ready.')
        ->not->toContain('FAIL');
});

test('healthy doctor json contains only valid json and consistent summary checks', function (): void {
    $data = talktoDoctorJson();
    $output = trim(talktoDoctorOutput());
    expect($output)->toStartWith('{')->toEndWith('}')
        ->and($data)->toHaveKeys(['status', 'summary', 'checks'])
        ->and($data['status'])->toBe('ready')
        ->and($data['summary'])->toHaveKeys(['pass', 'warn', 'fail', 'info'])
        ->and($data['summary']['fail'])->toBe(0);
    foreach ($data['checks'] as $check) {
        expect($check)->toHaveKeys(['category', 'key', 'status', 'label', 'value', 'message'])
            ->and($check['status'])->toBeIn(['pass', 'warn', 'fail', 'info']);
    }
    foreach ($data['summary'] as $status => $count) {
        expect($count)->toBe(count(array_filter($data['checks'], fn ($check) => $check['status'] === $status)));
    }
    expect(talktoDoctorCheck($data, 'php_version')['value'])->toBe(PHP_VERSION)
        ->and(talktoDoctorCheck($data, 'laravel_version')['value'])->toBe(app()->version())
        ->and(talktoDoctorCheck($data, 'talkto_version')['value'])->toBe('dev/source checkout')
        ->and(talktoDoctorCheck($data, 'outgoing_count')['value'])->toBe(2)
        ->and(talktoDoctorCheck($data, 'incoming_count')['value'])->toBe(1)
        ->and(talktoDoctorCheck($data, 'incoming_commands')['value'])->toBe(1);
});

test('missing or invalid service identity fails without echoing unsafe values', function (mixed $service): void {
    config(['talkto.service' => $service]);
    $data = talktoDoctorJson(1);
    expect($data['status'])->toBe('not_ready')
        ->and(talktoDoctorCheck($data, 'service_name')['status'])->toBe('fail');
})->with([null, '', 12, "service\r\ninjected", "service\0name", "invalid\xFF"]);

test('service identity uses existing rules rather than a new slug restriction', function (): void {
    config(['talkto.service' => 'service.with-dots_and spaces']);
    expect(talktoDoctorCheck(talktoDoctorJson(), 'service_name')['status'])->toBe('pass');
});

test('each missing storage family fails and doctor does not recreate its table', function (string $family, string $table): void {
    Schema::drop($table);
    $data = talktoDoctorJson(1);
    expect(talktoDoctorCheck($data, $family.'_table')['status'])->toBe('fail')
        ->and(Schema::hasTable($table))->toBeFalse();
})->with([
    ['messages', 'talkto_messages'], ['attempts', 'talkto_attempts'], ['events', 'talkto_events'],
    ['dead_letters', 'talkto_dead_letters'], ['nonces', 'talkto_nonces'],
]);

test('unavailable database connection fails without exposing credentials or exception details', function (): void {
    config([
        'talkto.database.connection' => 'doctor_broken',
        'database.connections.doctor_broken' => ['driver' => 'doctor-db-password', 'database' => ':memory:', 'password' => 'doctor-db-password'],
    ]);
    try {
        $data = talktoDoctorJson(1);
    } finally {
        config(['talkto.database.connection' => null]);
    }
    expect(talktoDoctorCheck($data, 'database_connection')['status'])->toBe('fail')
        ->and(talktoDoctorOutput())->not->toContain('doctor-db-password', 'Unsupported driver');
});

test('doctor respects configured storage models tables and database connection', function (): void {
    config([
        'database.connections.doctor_storage' => config('database.connections.sqlite'),
        'talkto.database.connection' => 'doctor_storage',
        'talkto.database.tables.messages' => 'doctor_messages',
        'talkto.database.tables.attempts' => 'doctor_attempts',
        'talkto.database.tables.events' => 'doctor_events',
        'talkto.database.tables.dead_letters' => 'doctor_dead_letters',
        'talkto.database.tables.nonces' => 'doctor_nonces',
        'talkto.models.message' => DoctorCustomMessage::class,
    ]);
    $this->loadMigrationsFrom(['--path' => __DIR__.'/../../database/migrations', '--database' => 'doctor_storage']);
    $data = talktoDoctorJson();
    expect(talktoDoctorCheck($data, 'database_connection')['value'])->toBe('doctor_storage');
    foreach (['messages', 'attempts', 'events', 'dead_letters', 'nonces'] as $family) {
        expect(talktoDoctorCheck($data, $family.'_table')['status'])->toBe('pass')
            ->and(talktoDoctorCheck($data, $family.'_table')['value'])->toBe('doctor_'.$family);
    }
});

test('doctor respects the legacy dead letter table fallback', function (): void {
    Schema::rename('talkto_dead_letters', 'doctor_legacy_dlq');
    config(['talkto.database.tables.dead_letters' => null, 'talkto.dead_letter.table' => 'doctor_legacy_dlq']);
    expect(talktoDoctorCheck(talktoDoctorJson(), 'dead_letters_table')['value'])->toBe('doctor_legacy_dlq');
});

test('secure defaults pass the limited security readiness checks', function (): void {
    $data = talktoDoctorJson();
    foreach (['signature_version', 'accepted_signatures', 'require_signature', 'replay_protection.enabled', 'v2_nonce_protection', 'tls_verification'] as $key) {
        expect(talktoDoctorCheck($data, $key)['status'])->toBe('pass');
    }
    expect(talktoDoctorCheck($data, 'detailed_security_audit')['value'])->toBe('php artisan talkto:security-audit');
});

test('unsafe or invalid security prerequisites fail', function (string $configKey, mixed $value, string $checkKey): void {
    config([$configKey => $value]);
    expect(talktoDoctorCheck(talktoDoctorJson(1), $checkKey)['status'])->toBe('fail');
})->with([
    ['talkto.security.require_signature', false, 'require_signature'],
    ['talkto.security.replay_protection.enabled', false, 'replay_protection.enabled'],
    ['talkto.security.signature_version', 'v3', 'signature_version'],
    ['talkto.security.accept_versions', [], 'accepted_signatures'],
    ['talkto.security.accept_versions', ['v3'], 'accepted_signatures'],
    ['talkto.security.accept_versions', [['private' => 'hidden']], 'accepted_signatures'],
]);

test('risky supported security configurations warn without failing', function (string $configKey, mixed $value, string $checkKey): void {
    config([$configKey => $value]);
    $data = talktoDoctorJson();
    expect(talktoDoctorCheck($data, $checkKey)['status'])->toBe('warn')
        ->and($data['status'])->toBe('ready')
        ->and($data['summary']['warn'])->toBeGreaterThan(0);
})->with([
    ['talkto.security.signature_version', 'v1', 'signature_version'],
    ['talkto.security.accept_versions', ['v1', 'v2'], 'accepted_signatures'],
    ['talkto.security.replay_protection.require_nonce_for_v2', false, 'v2_nonce_protection'],
    ['talkto.http.verify_ssl', false, 'tls_verification'],
]);

test('peer tls overrides and unavailable ca bundle are diagnosed without printing the path', function (): void {
    config([
        'talkto.outgoing.inventory.verify_ssl' => 'false',
        'talkto.outgoing.billing.ca_bundle' => __DIR__.'/doctor-private-ca-path.pem',
    ]);
    $data = talktoDoctorJson();
    expect(talktoDoctorCheck($data, 'outgoing.inventory.tls')['status'])->toBe('warn')
        ->and(talktoDoctorCheck($data, 'outgoing.billing.tls')['status'])->toBe('warn')
        ->and(talktoDoctorOutput())->not->toContain('doctor-private-ca-path');
});

test('doctor does not inspect ca bundles through network or custom stream wrappers', function (): void {
    stream_wrapper_register('doctor-probe', DoctorForbiddenStream::class);
    DoctorForbiddenStream::$probes = 0;
    try {
        config(['talkto.http.ca_bundle' => 'doctor-probe://private-host/ca.pem']);
        expect(talktoDoctorCheck(talktoDoctorJson(), 'tls_verification')['status'])->toBe('warn')
            ->and(DoctorForbiddenStream::$probes)->toBe(0);
    } finally {
        stream_wrapper_unregister('doctor-probe');
    }
});

test('secrets headers url credentials and database passwords never appear in either output mode', function (bool $json): void {
    config([
        'talkto.outgoing.inventory.base_url' => 'https://doctor-user:doctor-url-pass@inventory.test?token=doctor-url-token',
        'talkto.outgoing.inventory.headers' => ['Authorization' => 'Bearer doctor-bearer-token', 'X-Private' => 'doctor-private-header'],
        'database.connections.sqlite.password' => 'doctor-database-pass',
    ]);
    expect(Artisan::call('talkto:doctor', $json ? ['--json' => true] : []))->toBe(0);
    expect(Artisan::output())->not->toContain(
        'doctor-inventory-secret', 'doctor-billing-secret', 'doctor-source-secret',
        'doctor-user', 'doctor-url-pass', 'doctor-url-token', 'doctor-bearer-token', 'doctor-private-header', 'doctor-database-pass'
    );
})->with([false, true]);

test('invalid outgoing prerequisites fail without printing raw peer data', function (array $override): void {
    config(['talkto.outgoing.inventory' => $override]);
    expect(talktoDoctorCheck(talktoDoctorJson(1), 'outgoing.inventory')['status'])->toBe('fail')
        ->and(talktoDoctorOutput())->not->toContain('doctor-private-value');
})->with([
    [['base_url' => 'https://inventory.test']],
    [['base_url' => 'ftp://doctor-private-value', 'secret' => 'secret']],
    [['base_url' => 'https://inventory.test', 'secret' => 'secret', 'headers' => ['X-Header' => "doctor-private-value\r\ninjected"]]],
]);

test('doctor includes programmatically registered outgoing targets', function (): void {
    app(TalktoOutgoingTargetRegistryContract::class)->register('programmatic', ['url' => 'https://programmatic.test', 'signing_secret' => 'programmatic-secret']);
    $data = talktoDoctorJson();
    expect(talktoDoctorCheck($data, 'outgoing.programmatic')['status'])->toBe('pass')
        ->and(talktoDoctorCheck($data, 'outgoing_count')['value'])->toBe(3)
        ->and(talktoDoctorOutput())->not->toContain('programmatic-secret');
});

test('valid incoming source supports list allowlists and signing secret alias', function (): void {
    config(['talkto.incoming.inventory' => ['signing_secret' => 'source-signing-secret', 'allowed_commands' => ['stock.reserve', 'stock.release']]]);
    $data = talktoDoctorJson();
    expect(talktoDoctorCheck($data, 'incoming.inventory')['status'])->toBe('pass')
        ->and(talktoDoctorCheck($data, 'incoming_commands')['value'])->toBe(2)
        ->and(talktoDoctorOutput())->not->toContain('source-signing-secret');
});

test('invalid incoming source secret allowlist driver or handler fails', function (array $source): void {
    config(['talkto.incoming.inventory' => $source]);
    expect(talktoDoctorCheck(talktoDoctorJson(1), 'incoming.inventory')['status'])->toBe('fail');
})->with([
    [['allowed_commands' => ['stock.reserve']]],
    [['secret' => 'source-secret']],
    [['secret' => 'source-secret', 'allowed_commands' => []]],
    [['secret' => 'source-secret', 'allowed_commands' => ['stock.reserve' => ['driver' => 'unsupported']]]],
    [['secret' => 'source-secret', 'allowed_commands' => ['stock.reserve' => ['handler' => 'MissingDoctorHandler']]]],
    [['secret' => 'source-secret', 'allowed_commands' => ['stock.reserve' => ['handler' => stdClass::class]]]],
]);

test('explicit allow all warns only when it is effective under current verifier semantics', function (): void {
    config(['talkto.incoming.inventory' => ['secret' => 'source-secret', 'allow_all_commands' => true]]);
    expect(talktoDoctorCheck(talktoDoctorJson(), 'incoming.inventory.allow_all')['status'])->toBe('warn');
    config(['talkto.incoming.inventory.allowed_commands' => []]);
    expect(talktoDoctorCheck(talktoDoctorJson(1), 'incoming.inventory')['status'])->toBe('fail');
});

test('incoming handler references are checked without construction or execution', function (): void {
    config(['talkto.incoming.inventory.allowed_commands' => ['stock.reserve' => ['handler' => DoctorNeverRunHandler::class]]]);
    expect(talktoDoctorCheck(talktoDoctorJson(), 'incoming.inventory')['status'])->toBe('pass');
    app(TalktoIncomingHandlerRegistryContract::class)->register('stock.reserve', DoctorNeverRunHandler::class);
    expect(talktoDoctorCheck(talktoDoctorJson(), 'handler.stock.reserve')['status'])->toBe('pass');
});

test('queue readiness inspects configuration without contacting backends', function (string $driver, string $environment, string $status): void {
    $originalEnvironment = app()->environment();
    app()->instance('env', $environment);
    config(['queue.default' => 'doctor_queue', 'queue.connections.doctor_queue' => ['driver' => $driver]]);
    try {
        expect(talktoDoctorCheck(talktoDoctorJson(), 'queue_connection')['status'])->toBe($status);
    } finally {
        app()->instance('env', $originalEnvironment);
    }
})->with([
    ['database', 'production', 'pass'], ['redis', 'production', 'pass'],
    ['sync', 'testing', 'info'], ['sync', 'local', 'info'],
    ['sync', 'production', 'warn'], ['sync', 'staging', 'warn'], ['null', 'production', 'warn'],
]);

test('missing queue configuration fails', function (): void {
    config(['queue.default' => 'doctor_missing']);
    expect(talktoDoctorCheck(talktoDoctorJson(1), 'queue_connection')['status'])->toBe('fail');
});

test('disabled routes migrations callbacks and panel are informational with prepared storage', function (): void {
    config(['talkto.callbacks.enabled' => false]);
    $data = talktoDoctorJson();
    foreach (['package_routes', 'package_migrations', 'result_callbacks', 'operations_panel'] as $key) {
        expect(talktoDoctorCheck($data, $key)['status'])->toBe('info')
            ->and(talktoDoctorCheck($data, $key)['value'])->toBe('disabled');
    }
});

test('enabled package routes are verified with configured names and proper controller methods', function (): void {
    config(['talkto.routes.enabled' => true, 'talkto.routes.receive_name' => 'doctor.receive', 'talkto.routes.callback_name' => 'doctor.callback']);
    Route::post('/doctor/receive', TalktoReceiveController::class)->name('doctor.receive');
    Route::post('/doctor/callback', TalktoResultCallbackController::class)->name('doctor.callback');
    Route::getRoutes()->refreshNameLookups();
    expect(talktoDoctorCheck(talktoDoctorJson(), 'package_routes')['status'])->toBe('pass');
});

test('enabled package routes that are absent or point to a different controller fail', function (bool $wrongController): void {
    config(['talkto.routes.enabled' => true]);
    if ($wrongController) {
        Route::post('/fake/receive', fn () => null)->name('talkto.receive');
        Route::post('/fake/callback', TalktoResultCallbackController::class)->name('talkto.callback');
        Route::getRoutes()->refreshNameLookups();
    }
    expect(talktoDoctorCheck(talktoDoctorJson(1), 'package_routes')['status'])->toBe('fail');
})->with([false, true]);

test('disabled callbacks do not require a package callback route or reverse target', function (): void {
    config(['talkto.callbacks.enabled' => false, 'talkto.routes.enabled' => true, 'talkto.outgoing.inventory' => null]);
    // Remove the unused reverse peer rather than retaining an invalid outgoing configuration.
    config(['talkto.outgoing' => []]);
    Route::post('/doctor/receive', TalktoReceiveController::class)->name('talkto.receive');
    Route::getRoutes()->refreshNameLookups();
    expect(talktoDoctorCheck(talktoDoctorJson(), 'package_routes')['status'])->toBe('pass');
});

test('automatic callbacks require a resolvable reverse peer but manual callback mode does not', function (): void {
    config(['talkto.outgoing' => []]);
    expect(talktoDoctorCheck(talktoDoctorJson(1), 'result_callbacks')['status'])->toBe('fail');
    config(['talkto.callbacks.auto_dispatch' => false]);
    expect(talktoDoctorCheck(talktoDoctorJson(), 'result_callbacks')['status'])->toBe('pass');
});

test('callback url readiness uses existing target inference rules', function (): void {
    config(['talkto.outgoing.inventory' => ['receive_url' => 'https://inventory.test/unusual', 'secret' => 'secret']]);
    expect(talktoDoctorCheck(talktoDoctorJson(1), 'result_callbacks')['status'])->toBe('fail');
    config(['talkto.outgoing.inventory.callback_url' => 'https://inventory.test/callback']);
    expect(talktoDoctorCheck(talktoDoctorJson(), 'result_callbacks')['status'])->toBe('pass');
});

test('callback command readiness follows existing scalar conversion semantics', function (): void {
    config(['talkto.callbacks.command' => 17]);
    expect(talktoDoctorCheck(talktoDoctorJson(), 'result_callbacks')['status'])->toBe('pass');
});

test('enabled panel verifies all expected named routes', function (): void {
    config(['talkto.panel.enabled' => true, 'talkto.panel.route.name' => 'doctor.panel.']);
    expect(talktoDoctorCheck(talktoDoctorJson(1), 'operations_panel')['status'])->toBe('fail');
    Route::as('doctor.panel.')->group(__DIR__.'/../../routes/panel.php');
    Route::getRoutes()->refreshNameLookups();
    expect(talktoDoctorCheck(talktoDoctorJson(), 'operations_panel')['status'])->toBe('pass');
});

test('doctor is read only across ledger rows queue http cache config and routes', function (): void {
    Bus::fake();
    Http::fake();
    $client = Mockery::mock(TalktoHttpClientWithOptions::class);
    $client->shouldReceive('post')->never();
    $client->shouldReceive('postWithOptions')->never();
    app()->instance(TalktoHttpClient::class, $client);
    Cache::shouldReceive('put')->never();
    Cache::shouldReceive('forever')->never();
    Cache::shouldReceive('forget')->never();
    Cache::shouldReceive('remember')->never();
    $configuration = app('config')->all();
    $routes = Route::getRoutes()->getRoutes();
    $classes = [TalktoMessage::class, TalktoAttempt::class, TalktoEvent::class, TalktoDeadLetter::class, TalktoNonce::class];
    $before = array_map(fn ($class) => $class::query()->count(), $classes);
    DB::enableQueryLog();

    talktoDoctorJson();

    foreach (DB::getQueryLog() as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    expect(array_map(fn ($class) => $class::query()->count(), $classes))->toBe($before)
        ->and(app('config')->all())->toBe($configuration)
        ->and(Route::getRoutes()->getRoutes())->toBe($routes);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

function talktoDoctorJson(int $exit = 0): array
{
    expect(Artisan::call('talkto:doctor', ['--json' => true]))->toBe($exit);

    return json_decode(talktoDoctorOutput(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
}

function talktoDoctorOutput(?string $output = null): string
{
    static $captured = '';
    if ($output !== null) {
        $captured = $output;
    }

    return $captured;
}

function talktoDoctorCheck(array $result, string $key): array
{
    $check = collect($result['checks'])->firstWhere('key', $key);
    expect($check)->toBeArray();

    return $check;
}

class DoctorCustomMessage extends TalktoMessage {}

class DoctorForbiddenStream
{
    public $context;

    public static int $probes = 0;

    public function url_stat(string $path, int $flags): false
    {
        self::$probes++;

        return false;
    }
}

class DoctorNeverRunHandler implements TalktoIncomingCommandHandler
{
    public function __construct()
    {
        throw new RuntimeException('Doctor must not construct business handlers.');
    }

    public function handle(TalktoMessage $message): IncomingCommandResultContract
    {
        throw new RuntimeException('Doctor must not execute business handlers.');
    }
}
