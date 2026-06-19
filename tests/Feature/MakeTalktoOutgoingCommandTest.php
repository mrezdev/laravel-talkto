<?php

use Illuminate\Support\Facades\Artisan;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoOutgoingScaffolder;

test('make outgoing command is registered in artisan', function (): void {
    expect(Artisan::all())->toHaveKey('talkto:make-outgoing');
});

test('make outgoing command creates expected normal outgoing files', function (): void {
    $basePath = talktoPhase2TempBasePath();

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]);

    expect($exitCode)->toBe(0);

    $files = talktoPhase2OutgoingFiles($basePath);

    foreach (talktoPhase2NormalOutgoingFiles($files) as $file) {
        expect(is_file($file))->toBeTrue();
    }

    expect(is_file($files['source_action']))->toBeFalse();

    $client = file_get_contents($files['client']);
    $enum = file_get_contents($files['enum']);
    $send = file_get_contents($files['send']);
    $payload = file_get_contents($files['payload']);

    expect($client)->toContain('namespace App\\Talkto\\Outgoing\\Inventory;')
        ->and($client)->toContain('class InventoryTalktoClient')
        ->and($client)->toContain('public function verifyInvoice(mixed $source): TalktoMessage')
        ->and($client)->toContain('Commands\\VerifyInvoice\\SendVerifyInvoiceToInventory::class')
        ->and($client)->toContain('// talkto:outgoing-methods')
        ->and($enum)->toContain('enum InventoryOutgoingCommand: string')
        ->and($enum)->toContain("case VerifyInvoice = 'inventory.verify-invoice';")
        ->and($enum)->toContain('// talkto:outgoing-command-cases')
        ->and($send)->toContain('namespace App\\Talkto\\Outgoing\\Inventory\\Commands\\VerifyInvoice;')
        ->and($send)->toContain('class SendVerifyInvoiceToInventory')
        ->and($send)->toContain('private readonly TalktoFlowFactory $talkto')
        ->and($send)->toContain('private readonly VerifyInvoicePayloadBuilder $payloadBuilder')
        ->and($send)->toContain("->flow('app.inventory.verify-invoice')")
        ->and($send)->toContain("->to('inventory')")
        ->and($send)->toContain('->command(InventoryOutgoingCommand::VerifyInvoice->value)')
        ->and($send)->toContain('InvalidArgumentException')
        ->and($payload)->toContain('class VerifyInvoicePayloadBuilder')
        ->and($payload)->toContain('public function fromSource(mixed $source): array')
        ->and($payload)->toContain("'source_id' => data_get(\$source, 'id')");

    foreach (talktoPhase2NormalOutgoingFiles($files) as $file) {
        expect(file_get_contents($file))->not->toMatch('/{{\s*[^}]+\s*}}/');
    }

    $output = Artisan::output();

    expect($output)->toContain('app(\\App\\Talkto\\Outgoing\\Inventory\\InventoryTalktoClient::class)');
    expect($output)->toContain('method=verifyInvoice');
    expect($output)->toContain('transactional=false');

    expect($send)->not->toContain('Illuminate\\Support\\Facades\\DB')
        ->and($send)->not->toContain('sourceAction')
        ->and($send)->not->toContain('handleTransactionally');
});

test('make outgoing dry run creates no files and shows intended paths', function (): void {
    $basePath = talktoPhase2TempBasePath();

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--dry-run' => true,
    ]);

    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect(is_dir($basePath))->toBeFalse();
    expect($output)->toContain('dry_run=true');
    expect($output)->toContain('Intended files/updates:');

    $dryRun = app(TalktoOutgoingScaffolder::class)->scaffold(
        service: 'inventory',
        command: 'verify-invoice',
        dryRun: true,
        basePath: $basePath,
    );

    expect($dryRun->intended)->toContain(talktoPhase2NormalizePath($basePath).'/Outgoing/Inventory/InventoryTalktoClient.php')
        ->and($dryRun->intended)->toContain(talktoPhase2NormalizePath($basePath).'/Outgoing/Inventory/Commands/VerifyInvoice/SendVerifyInvoiceToInventory.php')
        ->and(is_dir($basePath))->toBeFalse();
});

test('make outgoing rejects dotted outgoing command names', function (): void {
    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'inventory.verify-invoice',
        '--base-path' => talktoPhase2TempBasePath(),
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Outgoing command name must be short and must not contain dots.');
});

test('make outgoing does not overwrite command specific files without force', function (): void {
    $basePath = talktoPhase2TempBasePath();

    Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase2OutgoingFiles($basePath);
    file_put_contents($files['send'], 'custom send action');
    file_put_contents($files['payload'], 'custom payload builder');

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['send']))->toBe('custom send action')
        ->and(file_get_contents($files['payload']))->toBe('custom payload builder')
        ->and(Artisan::output())->toContain('Skipped files:');
});

test('make outgoing overwrites command specific files with force', function (): void {
    $basePath = talktoPhase2TempBasePath();

    Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase2OutgoingFiles($basePath);
    file_put_contents($files['send'], 'custom send action');
    file_put_contents($files['payload'], 'custom payload builder');

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['send']))->toContain('class SendVerifyInvoiceToInventory')
        ->and(file_get_contents($files['payload']))->toContain('class VerifyInvoicePayloadBuilder')
        ->and(Artisan::output())->toContain('Overwritten files:');
});

test('second outgoing command for same service adds method and enum case without duplicating first', function (): void {
    $basePath = talktoPhase2TempBasePath();

    Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
    ]);

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'confirm-order',
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase2OutgoingFiles($basePath);
    $client = file_get_contents($files['client']);
    $enum = file_get_contents($files['enum']);

    expect($exitCode)->toBe(0)
        ->and(substr_count($client, 'function verifyInvoice('))->toBe(1)
        ->and(substr_count($client, 'function confirmOrder('))->toBe(1)
        ->and(substr_count($enum, 'case VerifyInvoice'))->toBe(1)
        ->and(substr_count($enum, 'case ConfirmOrder'))->toBe(1)
        ->and(is_file(talktoPhase2NormalizePath($basePath).'/Outgoing/Inventory/Commands/ConfirmOrder/SendConfirmOrderToInventory.php'))->toBeTrue()
        ->and(is_file(talktoPhase2NormalizePath($basePath).'/Outgoing/Inventory/Commands/ConfirmOrder/ConfirmOrderPayloadBuilder.php'))->toBeTrue();
});

test('running same outgoing command twice does not duplicate client method or enum case', function (): void {
    $basePath = talktoPhase2TempBasePath();

    Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
    ]);

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase2OutgoingFiles($basePath);
    $client = file_get_contents($files['client']);
    $enum = file_get_contents($files['enum']);

    expect($exitCode)->toBe(0)
        ->and(substr_count($client, 'function verifyInvoice('))->toBe(1)
        ->and(substr_count($enum, 'case VerifyInvoice'))->toBe(1);
});

test('existing client file without marker is not overwritten and shows manual update warning', function (): void {
    $basePath = talktoPhase2TempBasePath();
    $files = talktoPhase2OutgoingFiles($basePath);
    mkdir(dirname($files['client']), 0775, true);
    file_put_contents($files['client'], "<?php\n\nclass CustomClient {}\n");

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['client']))->toBe("<?php\n\nclass CustomClient {}\n")
        ->and(Artisan::output())->toContain('missing the Talkto outgoing methods marker');
});

test('existing enum file without marker is not overwritten and shows manual update warning', function (): void {
    $basePath = talktoPhase2TempBasePath();
    $files = talktoPhase2OutgoingFiles($basePath);
    mkdir(dirname($files['enum']), 0775, true);
    file_put_contents($files['enum'], "<?php\n\nenum CustomEnum: string {}\n");

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--force' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(file_get_contents($files['enum']))->toBe("<?php\n\nenum CustomEnum: string {}\n")
        ->and(Artisan::output())->toContain('missing the Talkto outgoing command cases marker');
});

test('make outgoing transactional creates normal files plus source action', function (): void {
    $basePath = talktoPhase2TempBasePath();

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
        '--transactional' => true,
    ]);

    expect($exitCode)->toBe(0);

    $files = talktoPhase2OutgoingFiles($basePath);

    foreach ($files as $file) {
        expect(is_file($file))->toBeTrue();
    }

    $client = file_get_contents($files['client']);
    $send = file_get_contents($files['send']);
    $sourceAction = file_get_contents($files['source_action']);

    expect($client)->toContain('public function verifyInvoice(mixed $source): TalktoMessage')
        ->and($client)->toContain('public function verifyInvoiceTransactionally(array $data): TalktoMessage')
        ->and($client)->toContain('->handleTransactionally($data)')
        ->and($send)->toContain('use Illuminate\\Support\\Facades\\DB;')
        ->and($send)->toContain('private readonly PrepareVerifyInvoiceSourceAction $sourceAction')
        ->and($send)->toContain('public function handleTransactionally(array $data): TalktoMessage')
        ->and($send)->toContain('DB::transaction')
        ->and($send)->toContain('private function sendForSource(mixed $source): TalktoMessage')
        ->and($sourceAction)->toContain('namespace App\\Talkto\\Outgoing\\Inventory\\Commands\\VerifyInvoice;')
        ->and($sourceAction)->toContain('class PrepareVerifyInvoiceSourceAction')
        ->and($sourceAction)->toContain('Do not call the remote service here.')
        ->and($sourceAction)->not->toMatch('/{{\s*[^}]+\s*}}/');

    $output = Artisan::output();

    expect($output)->toContain('transactional=true');
    expect($output)->toContain('transactional_method=verifyInvoiceTransactionally');
    expect($output)->toContain('Transactional:');
});

test('make outgoing transactional dry run creates no files and reports source action path', function (): void {
    $basePath = talktoPhase2TempBasePath();

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--transactional' => true,
        '--dry-run' => true,
    ]);

    $dryRun = app(TalktoOutgoingScaffolder::class)->scaffold(
        service: 'inventory',
        command: 'verify-invoice',
        dryRun: true,
        transactional: true,
        basePath: $basePath,
    );

    expect($exitCode)->toBe(0);
    expect(is_dir($basePath))->toBeFalse();
    expect(Artisan::output())->toContain('transactional=true');
    expect($dryRun->intended)->toContain(talktoPhase2NormalizePath($basePath).'/Outgoing/Inventory/Commands/VerifyInvoice/PrepareVerifyInvoiceSourceAction.php');
    expect($dryRun->intended)->toContain(talktoPhase2NormalizePath($basePath).'/Outgoing/Inventory/InventoryTalktoClient.php');
});

test('normal first then transactional without force creates source action and does not overwrite send action', function (): void {
    $basePath = talktoPhase2TempBasePath();

    Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase2OutgoingFiles($basePath);
    $originalSend = file_get_contents($files['send']);

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--transactional' => true,
    ]);

    $client = file_get_contents($files['client']);

    expect($exitCode)->toBe(0)
        ->and(is_file($files['source_action']))->toBeTrue()
        ->and($client)->toContain('function verifyInvoice(')
        ->and($client)->toContain('function verifyInvoiceTransactionally(')
        ->and(file_get_contents($files['send']))->toBe($originalSend)
        ->and(file_get_contents($files['send']))->not->toContain('handleTransactionally')
        ->and(Artisan::output())->toContain('rerun with --force to regenerate the transactional send action');
});

test('normal first then transactional with force overwrites send action with transactional version', function (): void {
    $basePath = talktoPhase2TempBasePath();

    Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
    ]);

    $files = talktoPhase2OutgoingFiles($basePath);
    file_put_contents($files['send'], 'custom normal send action');

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--transactional' => true,
        '--force' => true,
    ]);

    $send = file_get_contents($files['send']);

    expect($exitCode)->toBe(0)
        ->and($send)->toContain('handleTransactionally(array $data): TalktoMessage')
        ->and($send)->toContain('DB::transaction')
        ->and($send)->toContain('PrepareVerifyInvoiceSourceAction')
        ->and(is_file($files['source_action']))->toBeTrue();
});

test('running transactional twice does not duplicate client methods or enum cases', function (): void {
    $basePath = talktoPhase2TempBasePath();

    Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--transactional' => true,
    ]);

    $exitCode = Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--transactional' => true,
    ]);

    $files = talktoPhase2OutgoingFiles($basePath);
    $client = file_get_contents($files['client']);
    $enum = file_get_contents($files['enum']);

    expect($exitCode)->toBe(0)
        ->and(substr_count($client, 'function verifyInvoice('))->toBe(1)
        ->and(substr_count($client, 'function verifyInvoiceTransactionally('))->toBe(1)
        ->and(substr_count($enum, 'case VerifyInvoice'))->toBe(1);
});

function talktoPhase2TempBasePath(): string
{
    return talktoPhase2NormalizePath(sys_get_temp_dir().'/talkto-phase2-'.bin2hex(random_bytes(8)).'/app/Talkto');
}

function talktoPhase2NormalizePath(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function talktoPhase2OutgoingFiles(string $basePath): array
{
    $basePath = talktoPhase2NormalizePath($basePath);

    return [
        'client' => $basePath.'/Outgoing/Inventory/InventoryTalktoClient.php',
        'enum' => $basePath.'/Outgoing/Inventory/InventoryOutgoingCommand.php',
        'send' => $basePath.'/Outgoing/Inventory/Commands/VerifyInvoice/SendVerifyInvoiceToInventory.php',
        'payload' => $basePath.'/Outgoing/Inventory/Commands/VerifyInvoice/VerifyInvoicePayloadBuilder.php',
        'source_action' => $basePath.'/Outgoing/Inventory/Commands/VerifyInvoice/PrepareVerifyInvoiceSourceAction.php',
    ];
}

function talktoPhase2NormalOutgoingFiles(array $files): array
{
    return [
        $files['client'],
        $files['enum'],
        $files['send'],
        $files['payload'],
    ];
}
