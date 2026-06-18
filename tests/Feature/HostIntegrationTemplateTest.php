<?php

if (! function_exists('p48PackagePath')) {
    function p48PackagePath(string $path): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}

if (! function_exists('p48StubPaths')) {
    function p48StubPaths(): array
    {
        return [
            'stubs/host/config/talkto.php.stub',
            'stubs/host/app/Services/Talkto/Commands/ExamplePayloadBuilder.php.stub',
            'stubs/host/app/Services/Talkto/Commands/ExampleCommandSender.php.stub',
            'stubs/host/app/Services/Talkto/Handlers/ExampleCommandHandler.php.stub',
            'stubs/host/app/Services/Talkto/Callbacks/ExampleResultCallbackHandler.php.stub',
            'stubs/host/app/Http/Controllers/Talkto/TestingHealthController.php.stub',
            'stubs/host/tests/Feature/Talkto/ExampleOutgoingCommandTest.php.stub',
            'stubs/host/tests/Feature/Talkto/ExampleIncomingCommandTest.php.stub',
            'stubs/host/tests/Feature/Talkto/ExampleResultCallbackTest.php.stub',
            'stubs/host/tests/Feature/Talkto/ExampleLocalHttpE2ETest.php.stub',
            'stubs/host/README.md',
        ];
    }
}

if (! function_exists('p48ForbiddenProjectTerms')) {
    function p48ForbiddenProjectTerms(): array
    {
        return [
            'Verify'.'Invoice',
            'Dem'.'and',
            'App'.'eal',
            'Hy'.'brid',
            'Material'.'Detail',
            'create:receive-bulks-'.'hybrid',
            'receive-bulks-'.'hybrid',
            'product_'.'inventory',
            'ware'.'house',
            'ibake'.'_testing',
            'inventory'.'_testing',
        ];
    }
}

if (! function_exists('p48ObviousSecretPatterns')) {
    function p48ObviousSecretPatterns(): array
    {
        return [
            'APP_'.'KEY=',
            'WEBHOOK_CLIENT_'.'SECRET=',
            'local-talkto-test-'.'secret',
            'pass'.'word=',
            'tok'.'en=',
        ];
    }
}

if (! function_exists('p48ReadPackageFile')) {
    function p48ReadPackageFile(string $path): string
    {
        return file_get_contents(p48PackagePath($path)) ?: '';
    }
}

test('required host integration stubs exist', function (): void {
    foreach (p48StubPaths() as $path) {
        expect(p48PackagePath($path))->toBeFile();
    }
});

test('host stubs use generic placeholders and example names', function (): void {
    $combined = implode("\n", array_map(
        fn (string $path): string => p48ReadPackageFile($path),
        p48StubPaths(),
    ));

    expect($combined)->toContain('<source-service>')
        ->and($combined)->toContain('<destination-service>')
        ->and($combined)->toContain('<local-test-secret>')
        ->and($combined)->toContain('<service-testing-db>')
        ->and($combined)->toContain('http://127.0.0.1:<port>')
        ->and($combined)->toContain('example:sync-record')
        ->and($combined)->toContain('ExampleRecord')
        ->and($combined)->toContain('ExamplePayload');
});

test('host stubs stay free of project terms and committed secret values', function (): void {
    $combined = implode("\n", array_map(
        fn (string $path): string => p48ReadPackageFile($path),
        p48StubPaths(),
    ));

    foreach (array_merge(p48ForbiddenProjectTerms(), p48ObviousSecretPatterns()) as $term) {
        expect($combined)->not->toContain($term);
    }
});
