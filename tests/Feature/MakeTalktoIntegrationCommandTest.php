<?php

use Illuminate\Support\Facades\Artisan;

test('make integration command is registered in artisan', function (): void {
    expect(Artisan::all())->toHaveKey('talkto:make-integration');
});

test('make integration fails when no direction is selected', function (): void {
    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Choose exactly one direction');
});

test('make integration fails when both directions are selected', function (): void {
    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--incoming' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Do not use --outgoing and --incoming together');
});

test('make integration fails when incoming is transactional', function (): void {
    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--transactional' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('--transactional is only valid with --outgoing');
});

test('make integration outgoing rejects dotted command names through outgoing resolver', function (): void {
    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'inventory.verify-invoice',
        '--outgoing' => true,
        '--base-path' => talktoPhase5TempBasePath(),
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Outgoing command name must be short and must not contain dots.');
});

test('make integration incoming accepts dotted command names', function (): void {
    $basePath = talktoPhase5TempBasePath();

    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--base-path' => $basePath,
    ]);

    expect($exitCode)->toBe(0)
        ->and(is_file(talktoPhase5IncomingFiles($basePath)['handler']))->toBeTrue();
});

test('make integration outgoing creates normal outgoing files', function (): void {
    $basePath = talktoPhase5TempBasePath();

    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]);

    $files = talktoPhase5OutgoingFiles($basePath);
    $client = file_get_contents($files['client']);
    $enum = file_get_contents($files['enum']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(is_file($files['client']))->toBeTrue()
        ->and(is_file($files['send']))->toBeTrue()
        ->and(is_file($files['payload']))->toBeTrue()
        ->and(is_file($files['source_action']))->toBeFalse()
        ->and($client)->toContain('public function verifyInvoice(mixed $source): TalktoMessage')
        ->and($enum)->toContain("case VerifyInvoice = 'inventory.verify-invoice';");
    expect($output)->toContain('mode=outgoing');
    expect($output)->toContain('transactional=false');
});

test('make integration transactional outgoing creates source action and transactional send action', function (): void {
    $basePath = talktoPhase5TempBasePath();

    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--transactional' => true,
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase5OutgoingFiles($basePath);
    $send = file_get_contents($files['send']);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(is_file($files['source_action']))->toBeTrue()
        ->and($send)->toContain('handleTransactionally(array $data): TalktoMessage')
        ->and($send)->toContain('DB::transaction');
    expect($output)->toContain('mode=outgoing');
    expect($output)->toContain('transactional=true');
});

test('make integration incoming creates incoming files and config snippet', function (): void {
    $basePath = talktoPhase5TempBasePath();

    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]);

    $files = talktoPhase5IncomingFiles($basePath);
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(is_file($files['enum']))->toBeTrue()
        ->and(is_file($files['handler']))->toBeTrue()
        ->and(is_file($files['action']))->toBeTrue()
        ->and(is_file($files['validator']))->toBeTrue()
        ->and(file_get_contents($files['handler']))->toContain('class InvoiceVerifiedHandler implements TalktoIncomingCommandHandler');
    expect($output)->toContain('mode=incoming');
    expect($output)->toContain('Config snippet:');
    expect($output)->toContain('App\\Talkto\\Incoming\\Inventory\\Commands\\InvoiceVerified\\InvoiceVerifiedHandler::class');
});

test('make integration outgoing dry run creates no files and shows intended paths', function (): void {
    $basePath = talktoPhase5TempBasePath();

    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--dry-run' => true,
        '--base-path' => $basePath,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(is_dir($basePath))->toBeFalse();
    expect($output)->toContain('dry_run=true');
    expect($output)->toContain('Intended files/updates:');
});

test('make integration incoming dry run creates no files and shows config snippet', function (): void {
    $basePath = talktoPhase5TempBasePath();

    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--dry-run' => true,
        '--base-path' => $basePath,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(is_dir($basePath))->toBeFalse();
    expect($output)->toContain('dry_run=true');
    expect($output)->toContain('Config snippet:');
    expect($output)->toContain("'website.invoice-verified' => [");
});

test('make integration passes force through to outgoing scaffolder', function (): void {
    $basePath = talktoPhase5TempBasePath();

    Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase5OutgoingFiles($basePath);
    file_put_contents($files['send'], 'custom outgoing send');

    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--base-path' => $basePath,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['send']))->toContain('class SendVerifyInvoiceToInventory')
        ->and(Artisan::output())->toContain('Overwritten files:');
});

test('make integration passes force through to incoming scaffolder', function (): void {
    $basePath = talktoPhase5TempBasePath();

    Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase5IncomingFiles($basePath);
    file_put_contents($files['handler'], 'custom incoming handler');

    $exitCode = Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--base-path' => $basePath,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['handler']))->toContain('class InvoiceVerifiedHandler implements TalktoIncomingCommandHandler')
        ->and(Artisan::output())->toContain('Overwritten files:');
});

function talktoPhase5TempBasePath(): string
{
    return talktoPhase5NormalizePath(sys_get_temp_dir().'/talkto-phase5-'.bin2hex(random_bytes(8)).'/app/Talkto');
}

function talktoPhase5NormalizePath(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function talktoPhase5OutgoingFiles(string $basePath): array
{
    $basePath = talktoPhase5NormalizePath($basePath);

    return [
        'client' => $basePath.'/Outgoing/Inventory/InventoryTalktoClient.php',
        'enum' => $basePath.'/Outgoing/Inventory/InventoryOutgoingCommand.php',
        'send' => $basePath.'/Outgoing/Inventory/Commands/VerifyInvoice/SendVerifyInvoiceToInventory.php',
        'payload' => $basePath.'/Outgoing/Inventory/Commands/VerifyInvoice/VerifyInvoicePayloadBuilder.php',
        'source_action' => $basePath.'/Outgoing/Inventory/Commands/VerifyInvoice/PrepareVerifyInvoiceSourceAction.php',
    ];
}

function talktoPhase5IncomingFiles(string $basePath): array
{
    $basePath = talktoPhase5NormalizePath($basePath);

    return [
        'enum' => $basePath.'/Incoming/Inventory/InventoryIncomingCommand.php',
        'handler' => $basePath.'/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedHandler.php',
        'action' => $basePath.'/Incoming/Inventory/Commands/InvoiceVerified/HandleInvoiceVerifiedFromInventory.php',
        'validator' => $basePath.'/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedPayloadValidator.php',
    ];
}
