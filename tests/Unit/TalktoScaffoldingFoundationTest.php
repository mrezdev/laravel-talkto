<?php

use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoScaffoldNameResolver;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoScaffoldPathResolver;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoScaffoldWriter;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoStubRenderer;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldFile;

test('service names are normalized for scaffold generation', function (string $input): void {
    $names = app(TalktoScaffoldNameResolver::class)->resolveService($input);

    expect($names->originalService)->toBe($input)
        ->and($names->serviceKebab)->toBe('inventory')
        ->and($names->serviceStudly)->toBe('Inventory')
        ->and($names->serviceCamel)->toBe('inventory')
        ->and($names->serviceVariable)->toBe('inventory')
        ->and($names->serviceNamespaceSegment)->toBe('Inventory');
})->with([
    'inventory',
    'Inventory',
    'inventory-service',
    'inventory_service',
]);

test('service names reject empty and unsafe values', function (?string $service): void {
    app(TalktoScaffoldNameResolver::class)->resolveService((string) $service);
})->with([
    '',
    '../inventory',
    'inventory/service',
    'inventory\\service',
])->throws(InvalidArgumentException::class);

test('outgoing command names are normalized for scaffold generation', function (): void {
    $names = app(TalktoScaffoldNameResolver::class)->resolveOutgoing('inventory', 'verify-invoice');

    expect($names->originalCommand)->toBe('verify-invoice')
        ->and($names->commandKebab)->toBe('verify-invoice')
        ->and($names->commandStudly)->toBe('VerifyInvoice')
        ->and($names->commandCamel)->toBe('verifyInvoice')
        ->and($names->commandEnumCase)->toBe('VerifyInvoice')
        ->and($names->outgoingCommandValue)->toBe('inventory.verify-invoice')
        ->and($names->outgoingSendClass)->toBe('SendVerifyInvoiceToInventory')
        ->and($names->outgoingPayloadBuilderClass)->toBe('VerifyInvoicePayloadBuilder')
        ->and($names->outgoingTransactionalSourceActionClass)->toBe('PrepareVerifyInvoiceSourceAction')
        ->and($names->outgoingClientMethod)->toBe('verifyInvoice')
        ->and($names->outgoingTransactionalClientMethod)->toBe('verifyInvoiceTransactionally');
});

test('outgoing command names must be short and cannot contain dots', function (): void {
    app(TalktoScaffoldNameResolver::class)->resolveOutgoing('inventory', 'inventory.verify-invoice');
})->throws(InvalidArgumentException::class, 'Outgoing command name must be short and must not contain dots.');

test('incoming full command names are normalized for scaffold generation', function (): void {
    $names = app(TalktoScaffoldNameResolver::class)->resolveIncoming('inventory', 'website.invoice-verified');

    expect($names->commandValue)->toBe('website.invoice-verified')
        ->and($names->commandShortKebab)->toBe('invoice-verified')
        ->and($names->commandStudly)->toBe('InvoiceVerified')
        ->and($names->commandCamel)->toBe('invoiceVerified')
        ->and($names->commandEnumCase)->toBe('InvoiceVerified')
        ->and($names->incomingHandlerClass)->toBe('InvoiceVerifiedHandler')
        ->and($names->incomingActionClass)->toBe('HandleInvoiceVerifiedFromInventory')
        ->and($names->incomingValidatorClass)->toBe('InvoiceVerifiedPayloadValidator');
});

test('command names reject empty and unsafe values', function (string $command): void {
    app(TalktoScaffoldNameResolver::class)->resolveIncoming('inventory', $command);
})->with([
    '',
    '../invoice-verified',
    'website/invoice-verified',
    'website\\invoice-verified',
    'website..invoice-verified',
])->throws(InvalidArgumentException::class);

test('outgoing scaffold paths and namespaces are resolved', function (): void {
    $paths = app(TalktoScaffoldPathResolver::class)->resolveOutgoing('inventory', 'verify-invoice');

    expect($paths->servicePath)->toBe('app/Talkto/Outgoing/Inventory')
        ->and($paths->commandPath)->toBe('app/Talkto/Outgoing/Inventory/Commands/VerifyInvoice')
        ->and($paths->serviceNamespace)->toBe('App\\Talkto\\Outgoing\\Inventory')
        ->and($paths->commandNamespace)->toBe('App\\Talkto\\Outgoing\\Inventory\\Commands\\VerifyInvoice')
        ->and($paths->files)->toBe([
            'client' => 'app/Talkto/Outgoing/Inventory/InventoryTalktoClient.php',
            'command_enum' => 'app/Talkto/Outgoing/Inventory/InventoryOutgoingCommand.php',
            'send_action' => 'app/Talkto/Outgoing/Inventory/Commands/VerifyInvoice/SendVerifyInvoiceToInventory.php',
            'payload_builder' => 'app/Talkto/Outgoing/Inventory/Commands/VerifyInvoice/VerifyInvoicePayloadBuilder.php',
            'transactional_source_action' => 'app/Talkto/Outgoing/Inventory/Commands/VerifyInvoice/PrepareVerifyInvoiceSourceAction.php',
        ]);
});

test('incoming scaffold paths and namespaces are resolved', function (): void {
    $paths = app(TalktoScaffoldPathResolver::class)->resolveIncoming('inventory', 'website.invoice-verified');

    expect($paths->servicePath)->toBe('app/Talkto/Incoming/Inventory')
        ->and($paths->commandPath)->toBe('app/Talkto/Incoming/Inventory/Commands/InvoiceVerified')
        ->and($paths->serviceNamespace)->toBe('App\\Talkto\\Incoming\\Inventory')
        ->and($paths->commandNamespace)->toBe('App\\Talkto\\Incoming\\Inventory\\Commands\\InvoiceVerified')
        ->and($paths->files)->toBe([
            'command_enum' => 'app/Talkto/Incoming/Inventory/InventoryIncomingCommand.php',
            'handler' => 'app/Talkto/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedHandler.php',
            'action' => 'app/Talkto/Incoming/Inventory/Commands/InvoiceVerified/HandleInvoiceVerifiedFromInventory.php',
            'validator' => 'app/Talkto/Incoming/Inventory/Commands/InvoiceVerified/InvoiceVerifiedPayloadValidator.php',
        ]);
});

test('scaffold paths support custom base path and namespace', function (): void {
    $paths = app(TalktoScaffoldPathResolver::class)->resolveOutgoing(
        service: 'inventory',
        command: 'verify-invoice',
        basePath: '/src/App/Talkto/',
        baseNamespace: '\\Domain\\Talkto\\',
    );

    expect($paths->file('client'))->toBe('/src/App/Talkto/Outgoing/Inventory/InventoryTalktoClient.php')
        ->and($paths->serviceNamespace)->toBe('Domain\\Talkto\\Outgoing\\Inventory');
});

test('scaffold base paths normalize relative and absolute inputs', function (string $basePath, string $expected): void {
    $paths = app(TalktoScaffoldPathResolver::class)->resolveOutgoing(
        service: 'inventory',
        command: 'verify-invoice',
        basePath: $basePath,
    );

    expect($paths->basePath)->toBe($expected)
        ->and($paths->file('client'))->toBe($expected.'/Outgoing/Inventory/InventoryTalktoClient.php');
})->with([
    ['app/Talkto', 'app/Talkto'],
    ['/app/Talkto/', '/app/Talkto'],
    ['/var/www/project/app/Talkto/', '/var/www/project/app/Talkto'],
    ['app\\Talkto', 'app/Talkto'],
]);

test('scaffold base namespace rejects empty values', function (): void {
    app(TalktoScaffoldPathResolver::class)->resolveOutgoing(
        service: 'inventory',
        command: 'verify-invoice',
        baseNamespace: '\\',
    );
})->throws(InvalidArgumentException::class, 'Base namespace cannot be empty.');

test('scaffold base namespace trims wrapping namespace separators', function (): void {
    $paths = app(TalktoScaffoldPathResolver::class)->resolveOutgoing(
        service: 'inventory',
        command: 'verify-invoice',
        baseNamespace: '\\App\\Talkto\\',
    );

    expect($paths->baseNamespace)->toBe('App\\Talkto')
        ->and($paths->serviceNamespace)->toBe('App\\Talkto\\Outgoing\\Inventory');
});

test('stub renderer replaces placeholders and detects unresolved placeholders', function (): void {
    $renderer = app(TalktoStubRenderer::class);
    $content = $renderer->render(
        'namespace {{ namespace }}; class {{ class }} { public string $service = "{{ service }}"; } {{ missing }}',
        [
            'namespace' => 'App\\Talkto',
            'class' => 'InventoryTalktoClient',
            'service' => 'inventory',
        ],
    );

    expect($content)->toContain('namespace App\\Talkto;')
        ->and($content)->toContain('class InventoryTalktoClient')
        ->and($content)->toContain('"inventory"')
        ->and($content)->toContain('{{ missing }}')
        ->and($renderer->hasUnresolvedPlaceholders($content))->toBeTrue()
        ->and($renderer->hasUnresolvedPlaceholders('final content'))->toBeFalse();
});

test('scaffold writer dry run returns intended files without writing', function (): void {
    $directory = talktoScaffoldTempDirectory();
    $path = $directory.'/Generated/File.php';

    $result = app(TalktoScaffoldWriter::class)->write([
        new TalktoScaffoldFile($path, '<?php echo "created";'),
    ], dryRun: true);

    expect($result->intended)->toBe([$path])
        ->and($result->created)->toBe([])
        ->and(is_file($path))->toBeFalse();
});

test('scaffold writer creates files', function (): void {
    $directory = talktoScaffoldTempDirectory();
    $path = $directory.'/Generated/File.php';

    $result = app(TalktoScaffoldWriter::class)->write([
        new TalktoScaffoldFile($path, 'created'),
    ]);

    expect($result->created)->toBe([$path])
        ->and($result->skipped)->toBe([])
        ->and(file_get_contents($path))->toBe('created');
});

test('scaffold writer does not overwrite existing files without force', function (): void {
    $directory = talktoScaffoldTempDirectory();
    $path = $directory.'/Generated/File.php';
    mkdir(dirname($path), 0775, true);
    file_put_contents($path, 'existing');

    $result = app(TalktoScaffoldWriter::class)->write([
        new TalktoScaffoldFile($path, 'new'),
    ]);

    expect($result->created)->toBe([])
        ->and($result->skipped)->toBe([$path])
        ->and(file_get_contents($path))->toBe('existing');
});

test('scaffold writer overwrites existing files with force', function (): void {
    $directory = talktoScaffoldTempDirectory();
    $path = $directory.'/Generated/File.php';
    mkdir(dirname($path), 0775, true);
    file_put_contents($path, 'existing');

    $result = app(TalktoScaffoldWriter::class)->write([
        new TalktoScaffoldFile($path, 'new'),
    ], force: true);

    expect($result->overwritten)->toBe([$path])
        ->and($result->skipped)->toBe([])
        ->and(file_get_contents($path))->toBe('new');
});

function talktoScaffoldTempDirectory(): string
{
    $directory = sys_get_temp_dir().'/talkto-scaffold-'.bin2hex(random_bytes(8));

    if (! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create temporary directory [{$directory}].");
    }

    return $directory;
}
