<?php

use Illuminate\Support\Facades\Artisan;

test('all generator modes produce placeholder free php shaped files', function (): void {
    $basePath = talktoPhase6TempBasePath();

    expect(Artisan::call('talkto:make-outgoing', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]))->toBe(0);

    expect(Artisan::call('talkto:make-outgoing', [
        'service' => 'billing',
        'talktoCommand' => 'charge-card',
        '--transactional' => true,
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]))->toBe(0);

    expect(Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]))->toBe(0);

    expect(Artisan::call('talkto:make-integration', [
        'service' => 'shipping',
        'talktoCommand' => 'create-label',
        '--outgoing' => true,
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]))->toBe(0);

    expect(Artisan::call('talkto:make-integration', [
        'service' => 'payments',
        'talktoCommand' => 'website.payment-captured',
        '--incoming' => true,
        '--base-path' => $basePath,
        '--base-namespace' => 'App\\Talkto',
    ]))->toBe(0);

    $phpFiles = talktoPhase6PhpFiles($basePath);

    expect($phpFiles)->toHaveCount(21);

    foreach ($phpFiles as $file) {
        $content = file_get_contents($file);

        expect($content)->toStartWith('<?php')
            ->and($content)->toContain('namespace App\\Talkto\\')
            ->and($content)->not->toMatch('/{{\s*[^}]+\s*}}/')
            ->and($content)->not->toContain('Http::')
            ->and($content)->not->toContain('curl_');

        talktoPhase6AssertPhpLintPasses($file);
    }
});

test('integration shortcut does not duplicate outgoing or incoming inserts', function (): void {
    $basePath = talktoPhase6TempBasePath();

    Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--base-path' => $basePath,
    ]);

    Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--base-path' => $basePath,
    ]);

    Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--transactional' => true,
        '--base-path' => $basePath,
    ]);

    Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--base-path' => $basePath,
    ]);

    Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--base-path' => $basePath,
    ]);

    Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'billing.payment-captured',
        '--incoming' => true,
        '--base-path' => $basePath,
    ]);

    $outgoingClient = file_get_contents(talktoPhase6Path($basePath, 'Outgoing/Inventory/InventoryTalktoClient.php'));
    $outgoingEnum = file_get_contents(talktoPhase6Path($basePath, 'Outgoing/Inventory/InventoryOutgoingCommand.php'));
    $incomingEnum = file_get_contents(talktoPhase6Path($basePath, 'Incoming/Inventory/InventoryIncomingCommand.php'));

    expect(substr_count($outgoingClient, 'function verifyInvoice('))->toBe(1)
        ->and(substr_count($outgoingClient, 'function verifyInvoiceTransactionally('))->toBe(1)
        ->and(substr_count($outgoingEnum, 'case VerifyInvoice'))->toBe(1)
        ->and(substr_count($incomingEnum, 'case InvoiceVerified'))->toBe(1)
        ->and(substr_count($incomingEnum, 'case PaymentCaptured'))->toBe(1)
        ->and(substr_count($incomingEnum, "'website.invoice-verified'"))->toBe(1);
});

test('integration shortcut keeps markerless service files untouched even with force', function (): void {
    $basePath = talktoPhase6TempBasePath();
    $client = talktoPhase6Path($basePath, 'Outgoing/Inventory/InventoryTalktoClient.php');
    $outgoingEnum = talktoPhase6Path($basePath, 'Outgoing/Inventory/InventoryOutgoingCommand.php');
    $incomingEnum = talktoPhase6Path($basePath, 'Incoming/Inventory/InventoryIncomingCommand.php');

    mkdir(dirname($client), 0775, true);
    mkdir(dirname($incomingEnum), 0775, true);

    file_put_contents($client, "<?php\n\nclass CustomInventoryClient {}\n");
    file_put_contents($outgoingEnum, "<?php\n\nenum CustomOutgoingCommand: string {}\n");
    file_put_contents($incomingEnum, "<?php\n\nenum CustomIncomingCommand: string {}\n");

    expect(Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'verify-invoice',
        '--outgoing' => true,
        '--force' => true,
        '--base-path' => $basePath,
    ]))->toBe(0);

    $outgoingOutput = Artisan::output();

    expect(file_get_contents($client))->toBe("<?php\n\nclass CustomInventoryClient {}\n")
        ->and(file_get_contents($outgoingEnum))->toBe("<?php\n\nenum CustomOutgoingCommand: string {}\n")
        ->and($outgoingOutput)->toContain('missing the Talkto outgoing methods marker')
        ->and($outgoingOutput)->toContain('missing the Talkto outgoing command cases marker');

    expect(Artisan::call('talkto:make-integration', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--incoming' => true,
        '--force' => true,
        '--base-path' => $basePath,
    ]))->toBe(0);

    expect(file_get_contents($incomingEnum))->toBe("<?php\n\nenum CustomIncomingCommand: string {}\n")
        ->and(Artisan::output())->toContain('missing the Talkto incoming command cases marker');
});

test('dry run commands do not create files and keep stable output sections', function (string $command, array $arguments, array $expectedLines): void {
    $basePath = talktoPhase6TempBasePath();
    $exitCode = Artisan::call($command, array_merge($arguments, [
        '--dry-run' => true,
        '--base-path' => $basePath,
    ]));

    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and(is_dir($basePath))->toBeFalse()
        ->and($output)->toContain('dry_run=true')
        ->and($output)->toContain('Intended files/updates:')
        ->and($output)->toContain('Created files: none')
        ->and($output)->toContain('Skipped files: none')
        ->and($output)->toContain('Overwritten files: none')
        ->and($output)->toContain('Manual update notes: none')
        ->and($output)->toContain('Errors: none');

    foreach ($expectedLines as $line) {
        expect($output)->toContain($line);
    }
})->with([
    'normal outgoing' => [
        'talkto:make-outgoing',
        ['service' => 'inventory', 'talktoCommand' => 'verify-invoice'],
        ['Talkto outgoing scaffold', 'service=inventory', 'command_value=inventory.verify-invoice', 'transactional=false', 'Example usage:'],
    ],
    'transactional outgoing' => [
        'talkto:make-outgoing',
        ['service' => 'inventory', 'talktoCommand' => 'verify-invoice', '--transactional' => true],
        ['Talkto outgoing scaffold', 'transactional=true', 'transactional_method=verifyInvoiceTransactionally', 'Example usage:'],
    ],
    'incoming' => [
        'talkto:make-incoming',
        ['service' => 'inventory', 'talktoCommand' => 'website.invoice-verified'],
        ['Talkto incoming scaffold', 'source_service=inventory', 'handler=App\\Talkto\\Incoming\\Inventory\\Commands\\InvoiceVerified\\InvoiceVerifiedHandler', 'Config snippet:'],
    ],
    'integration outgoing' => [
        'talkto:make-integration',
        ['service' => 'inventory', 'talktoCommand' => 'verify-invoice', '--outgoing' => true],
        ['Talkto integration scaffold', 'mode=outgoing', 'target_service=inventory', 'transactional=false', 'Example usage:'],
    ],
    'integration incoming' => [
        'talkto:make-integration',
        ['service' => 'inventory', 'talktoCommand' => 'website.invoice-verified', '--incoming' => true],
        ['Talkto integration scaffold', 'mode=incoming', 'source_service=inventory', 'Config snippet:'],
    ],
]);

test('public generators return validation errors without writing files', function (string $command, array $arguments, string $message): void {
    $basePath = talktoPhase6TempBasePath();
    $exitCode = Artisan::call($command, array_merge($arguments, [
        '--base-path' => $basePath,
    ]));

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain($message)
        ->and(is_dir($basePath))->toBeFalse();
})->with([
    'empty outgoing service' => [
        'talkto:make-outgoing',
        ['service' => '', 'talktoCommand' => 'verify-invoice'],
        'Service name cannot be empty.',
    ],
    'unsafe incoming service' => [
        'talkto:make-incoming',
        ['service' => 'inventory/service', 'talktoCommand' => 'website.invoice-verified'],
        'Service name contains unsafe characters.',
    ],
    'unsafe incoming command' => [
        'talkto:make-incoming',
        ['service' => 'inventory', 'talktoCommand' => 'website/invoice-verified'],
        'Command name contains unsafe characters.',
    ],
    'dotted integration outgoing command' => [
        'talkto:make-integration',
        ['service' => 'inventory', 'talktoCommand' => 'inventory.verify-invoice', '--outgoing' => true],
        'Outgoing command name must be short and must not contain dots.',
    ],
    'empty integration base namespace' => [
        'talkto:make-integration',
        ['service' => 'inventory', 'talktoCommand' => 'verify-invoice', '--outgoing' => true, '--base-namespace' => '\\'],
        'Base namespace cannot be empty.',
    ],
]);

test('generators preserve absolute base paths', function (): void {
    $basePath = talktoPhase6TempBasePath();

    expect(Artisan::call('talkto:make-incoming', [
        'service' => 'inventory',
        'talktoCommand' => 'website.invoice-verified',
        '--base-path' => $basePath.'/',
        '--base-namespace' => '\\App\\Talkto\\',
    ]))->toBe(0);

    expect(is_file(talktoPhase6Path($basePath, 'Incoming/Inventory/InventoryIncomingCommand.php')))->toBeTrue()
        ->and(file_get_contents(talktoPhase6Path($basePath, 'Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedHandler.php')))
        ->toContain('namespace App\\Talkto\\Incoming\\Inventory\\Commands\\InvoiceVerified;');
});

function talktoPhase6TempBasePath(): string
{
    return talktoPhase6NormalizePath(sys_get_temp_dir().'/talkto-phase6-'.bin2hex(random_bytes(8)).'/app/Talkto');
}

function talktoPhase6NormalizePath(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function talktoPhase6Path(string $basePath, string $path): string
{
    return talktoPhase6NormalizePath($basePath).'/'.ltrim($path, '/');
}

function talktoPhase6PhpFiles(string $basePath): array
{
    if (! is_dir($basePath)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = talktoPhase6NormalizePath($file->getPathname());
        }
    }

    sort($files);

    return $files;
}

function talktoPhase6AssertPhpLintPasses(string $file): void
{
    if (! function_exists('exec') || PHP_BINARY === '') {
        expect(file_get_contents($file))->toStartWith('<?php');

        return;
    }

    $output = [];
    $exitCode = 1;

    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file), $output, $exitCode);

    expect($exitCode, implode(PHP_EOL, $output))->toBe(0);
}
