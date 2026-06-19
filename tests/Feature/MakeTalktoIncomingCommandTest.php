<?php

use Illuminate\Support\Facades\Artisan;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoIncomingScaffolder;

test('make incoming command is registered in artisan', function (): void {
    expect(Artisan::all())->toHaveKey('talkto:make-incoming');
});

test('make incoming command creates expected incoming files', function (): void {
    $basePath = talktoPhase4TempBasePath();

    $exitCode = Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]);

    expect($exitCode)->toBe(0);

    $files = talktoPhase4IncomingFiles($basePath);

    foreach ($files as $file) {
        expect(is_file($file))->toBeTrue();
    }

    $enum = file_get_contents($files['enum']);
    $handler = file_get_contents($files['handler']);
    $action = file_get_contents($files['action']);
    $validator = file_get_contents($files['validator']);

    expect($enum)->toContain('namespace App\\Talkto\\Incoming\\Inventory;')
        ->and($enum)->toContain('enum InventoryIncomingCommand: string')
        ->and($enum)->toContain("case InvoiceVerified = 'website.invoice-verified';")
        ->and($enum)->toContain('// talkto:incoming-command-cases')
        ->and($handler)->toContain('namespace App\\Talkto\\Incoming\\Inventory\\Commands\\InvoiceVerified;')
        ->and($handler)->toContain('class InvoiceVerifiedHandler implements TalktoIncomingCommandHandler')
        ->and($handler)->toContain('private readonly InvoiceVerifiedPayloadValidator $validator')
        ->and($handler)->toContain('private readonly HandleInvoiceVerifiedFromInventory $action')
        ->and($handler)->toContain('public function handle(TalktoMessage $message): TalktoIncomingCommandResult')
        ->and($handler)->toContain('$payload = $message->payload ?? [];')
        ->and($handler)->toContain('$validated = $this->validator->validate($payload);')
        ->and($handler)->toContain('$result = $this->action->handle($validated, $message);')
        ->and($handler)->toContain('TalktoIncomingCommandResult::succeeded($result)')
        ->and($handler)->toContain("TalktoIncomingCommandResult::failedFinal(\$exception->getMessage(), 'validation_error')")
        ->and($handler)->toContain('TalktoIncomingCommandResult::failedRetryable($throwable->getMessage(), $throwable::class)')
        ->and($action)->toContain('class HandleInvoiceVerifiedFromInventory')
        ->and($action)->toContain('public function handle(array $payload, TalktoMessage $message): array')
        ->and($action)->toContain("'handled' => true")
        ->and($validator)->toContain('use InvalidArgumentException;')
        ->and($validator)->toContain('class InvoiceVerifiedPayloadValidator')
        ->and($validator)->toContain('public function validate(array $payload): array')
        ->and($validator)->toContain('return $payload;');

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toMatch('/{{\s*[^}]+\s*}}/');
    }

    $output = Artisan::output();

    expect($output)->toContain('source_service=inventory');
    expect($output)->toContain('command_value=website.invoice-verified');
    expect($output)->toContain('command_class_base=InvoiceVerified');
    expect($output)->toContain('handler=App\\Talkto\\Incoming\\Inventory\\Commands\\InvoiceVerified\\InvoiceVerifiedHandler');
});

test('make incoming dry run creates no files and shows intended paths and config snippet', function (): void {
    $basePath = talktoPhase4TempBasePath();

    $exitCode = Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
        '--dry-run' => true,
    ]);

    $output = Artisan::output();
    $dryRun = app(TalktoIncomingScaffolder::class)->scaffold(
        service: 'inventory',
        command: 'website.invoice-verified',
        dryRun: true,
        basePath: $basePath,
    );

    expect($exitCode)->toBe(0);
    expect(is_dir($basePath))->toBeFalse();
    expect($output)->toContain('dry_run=true');
    expect($output)->toContain('Config snippet:');
    expect($dryRun->configSnippet)->toContain("'website.invoice-verified' => [");
    expect($dryRun->configSnippet)->toContain('App\\Talkto\\Incoming\\Inventory\\Commands\\InvoiceVerified\\InvoiceVerifiedHandler::class');
    expect($dryRun->intended)->toContain(talktoPhase4NormalizePath($basePath).'/Incoming/Inventory/InventoryIncomingCommand.php');
    expect($dryRun->intended)->toContain(talktoPhase4NormalizePath($basePath).'/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedHandler.php');
    expect($dryRun->intended)->toContain(talktoPhase4NormalizePath($basePath).'/Incoming/Inventory/Commands/InvoiceVerified/HandleInvoiceVerifiedFromInventory.php');
    expect($dryRun->intended)->toContain(talktoPhase4NormalizePath($basePath).'/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedPayloadValidator.php');
    expect(is_dir($basePath))->toBeFalse();
});

test('incoming accepts dotted commands and uses last segment for command folder and classes', function (): void {
    $basePath = talktoPhase4TempBasePath();

    $exitCode = Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_file(talktoPhase4NormalizePath($basePath).'/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedHandler.php'))->toBeTrue()
        ->and(is_file(talktoPhase4NormalizePath($basePath).'/Incoming/Inventory/Commands/InvoiceVerified/HandleInvoiceVerifiedFromInventory.php'))->toBeTrue()
        ->and(is_file(talktoPhase4NormalizePath($basePath).'/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedPayloadValidator.php'))->toBeTrue();
});

test('make incoming does not overwrite command specific files without force', function (): void {
    $basePath = talktoPhase4TempBasePath();

    Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase4IncomingFiles($basePath);
    file_put_contents($files['handler'], 'custom handler');
    file_put_contents($files['action'], 'custom action');
    file_put_contents($files['validator'], 'custom validator');

    $exitCode = Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['handler']))->toBe('custom handler')
        ->and(file_get_contents($files['action']))->toBe('custom action')
        ->and(file_get_contents($files['validator']))->toBe('custom validator')
        ->and(Artisan::output())->toContain('Skipped files:');
});

test('make incoming overwrites command specific files with force', function (): void {
    $basePath = talktoPhase4TempBasePath();

    Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase4IncomingFiles($basePath);
    file_put_contents($files['handler'], 'custom handler');
    file_put_contents($files['action'], 'custom action');
    file_put_contents($files['validator'], 'custom validator');

    $exitCode = Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['handler']))->toContain('class InvoiceVerifiedHandler implements TalktoIncomingCommandHandler')
        ->and(file_get_contents($files['action']))->toContain('class HandleInvoiceVerifiedFromInventory')
        ->and(file_get_contents($files['validator']))->toContain('class InvoiceVerifiedPayloadValidator')
        ->and(Artisan::output())->toContain('Overwritten files:');
});

test('second incoming command for same service adds enum case without duplicating first', function (): void {
    $basePath = talktoPhase4TempBasePath();

    Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
    ]);

    $exitCode = Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'billing.payment-captured',
        '--base-path' => $basePath,
    ]);

    $enum = file_get_contents(talktoPhase4IncomingFiles($basePath)['enum']);

    expect($exitCode)->toBe(0)
        ->and(substr_count($enum, 'case InvoiceVerified'))->toBe(1)
        ->and(substr_count($enum, 'case PaymentCaptured'))->toBe(1)
        ->and($enum)->toContain("'website.invoice-verified'")
        ->and($enum)->toContain("'billing.payment-captured'")
        ->and(is_file(talktoPhase4NormalizePath($basePath).'/Incoming/Inventory/Commands/PaymentCaptured/PaymentCapturedHandler.php'))->toBeTrue();
});

test('running same incoming command twice does not duplicate enum case', function (): void {
    $basePath = talktoPhase4TempBasePath();

    Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
    ]);

    $exitCode = Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
    ]);

    $enum = file_get_contents(talktoPhase4IncomingFiles($basePath)['enum']);

    expect($exitCode)->toBe(0)
        ->and(substr_count($enum, 'case InvoiceVerified'))->toBe(1)
        ->and(substr_count($enum, "'website.invoice-verified'"))->toBe(1);
});

test('existing incoming enum without marker is not overwritten and shows manual update warning', function (): void {
    $basePath = talktoPhase4TempBasePath();
    $files = talktoPhase4IncomingFiles($basePath);
    mkdir(dirname($files['enum']), 0775, true);
    file_put_contents($files['enum'], "<?php\n\nenum CustomIncomingCommand: string {}\n");

    $exitCode = Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['enum']))->toBe("<?php\n\nenum CustomIncomingCommand: string {}\n")
        ->and(Artisan::output())->toContain('missing the Talkto incoming command cases marker');
});

test('incoming config snippet contains correct handler fqn and command value', function (): void {
    $result = app(TalktoIncomingScaffolder::class)->scaffold(
        service: 'inventory',
        command: 'website.invoice-verified',
        dryRun: true,
        basePath: talktoPhase4TempBasePath(),
        baseNamespace: 'App\\Talkto',
    );

    expect($result->configSnippet)->toContain("'inventory' => [")
        ->and($result->configSnippet)->toContain("'secret' => env('TALKTO_FROM_INVENTORY_SECRET')")
        ->and($result->configSnippet)->toContain("'website.invoice-verified' => [")
        ->and($result->configSnippet)->toContain("'handler' => App\\Talkto\\Incoming\\Inventory\\Commands\\InvoiceVerified\\InvoiceVerifiedHandler::class")
        ->and($result->configSnippet)->toContain("'idempotency' => 'required'");
});

function talktoPhase4TempBasePath(): string
{
    return talktoPhase4NormalizePath(sys_get_temp_dir().'/talkto-phase4-'.bin2hex(random_bytes(8)).'/app/Talkto');
}

function talktoPhase4NormalizePath(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function talktoPhase4IncomingFiles(string $basePath): array
{
    $basePath = talktoPhase4NormalizePath($basePath);

    return [
        'enum' => $basePath.'/Incoming/Inventory/InventoryIncomingCommand.php',
        'handler' => $basePath.'/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedHandler.php',
        'action' => $basePath.'/Incoming/Inventory/Commands/InvoiceVerified/HandleInvoiceVerifiedFromInventory.php',
        'validator' => $basePath.'/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedPayloadValidator.php',
    ];
}
