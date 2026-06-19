<?php

namespace Mrezdev\LaravelTalkto\Services\Scaffolding;

use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoOutgoingScaffoldResult;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldFile;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldNames;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldResult;
use RuntimeException;

final class TalktoOutgoingScaffolder
{
    private const CLIENT_METHOD_MARKER = '// talkto:outgoing-methods';

    private const ENUM_CASE_MARKER = '// talkto:outgoing-command-cases';

    public function __construct(
        private readonly TalktoScaffoldNameResolver $names,
        private readonly TalktoScaffoldPathResolver $paths,
        private readonly TalktoStubRenderer $renderer,
        private readonly TalktoScaffoldWriter $writer,
    ) {}

    public function scaffold(
        string $service,
        string $command,
        bool $dryRun = false,
        bool $force = false,
        bool $transactional = false,
        string $basePath = 'app/Talkto',
        string $baseNamespace = 'App\\Talkto',
    ): TalktoOutgoingScaffoldResult {
        $names = $this->names->resolveOutgoing($service, $command);
        $paths = $this->paths->resolveOutgoing($service, $command, $basePath, $baseNamespace);
        $clientClass = $names->serviceStudly.'TalktoClient';
        $enumClass = $names->serviceStudly.'OutgoingCommand';
        $clientFqn = $paths->serviceNamespace.'\\'.$clientClass;
        $state = [
            'created' => [],
            'skipped' => [],
            'overwritten' => [],
            'intended' => [],
            'warnings' => [],
            'manual_updates' => [],
            'errors' => [],
        ];

        $this->prepareClientFile($paths->file('client'), $clientClass, $names, $paths->serviceNamespace, $dryRun, $transactional, $state);
        $this->prepareEnumFile($paths->file('command_enum'), $enumClass, $names, $paths->serviceNamespace, $dryRun, $state);

        if ($transactional && is_file($paths->file('send_action')) && ! $force && ! $dryRun) {
            $sendAction = $this->readFile($paths->file('send_action'));

            if (! $this->clientMethodExists($sendAction, 'handleTransactionally')) {
                $message = "Transactional send action [{$paths->file('send_action')}] already exists and was not overwritten. Update it manually or rerun with --force to regenerate the transactional send action.";
                $state['warnings'][] = $message;
                $state['manual_updates'][] = $message;
            }
        }

        $commandFiles = [
            new TalktoScaffoldFile(
                $paths->file('send_action'),
                $this->renderStub($transactional ? 'outgoing-transactional-send-action.stub' : 'outgoing-send-action.stub', [
                    'namespace' => $paths->commandNamespace,
                    'class' => $names->outgoingSendClass,
                    'payload_builder_class' => $names->outgoingPayloadBuilderClass,
                    'source_action_class' => $names->outgoingTransactionalSourceActionClass,
                    'flow_name' => 'app.'.$names->outgoingCommandValue,
                    'service' => $names->serviceKebab,
                    'command_value' => $names->outgoingCommandValue,
                    'command_kebab' => $names->commandKebab,
                    'enum_fqn' => $paths->serviceNamespace.'\\'.$enumClass,
                    'enum_class' => $enumClass,
                    'enum_case' => $names->commandEnumCase,
                ]),
            ),
            new TalktoScaffoldFile(
                $paths->file('payload_builder'),
                $this->renderStub('outgoing-payload-builder.stub', [
                    'namespace' => $paths->commandNamespace,
                    'class' => $names->outgoingPayloadBuilderClass,
                ]),
            ),
        ];

        if ($transactional) {
            $commandFiles[] = new TalktoScaffoldFile(
                $paths->file('transactional_source_action'),
                $this->renderStub('outgoing-transactional-source-action.stub', [
                    'namespace' => $paths->commandNamespace,
                    'class' => $names->outgoingTransactionalSourceActionClass,
                    'send_class' => $names->outgoingSendClass,
                ]),
            );
        }

        $commandWrite = $this->writer->write($commandFiles, dryRun: $dryRun, force: $force);

        $this->mergeWriteResult($state, $commandWrite);

        return new TalktoOutgoingScaffoldResult(
            names: $names,
            paths: $paths,
            clientClass: $clientClass,
            clientFqn: $clientFqn,
            clientMethod: (string) $names->outgoingClientMethod,
            transactionalClientMethod: (string) $names->outgoingTransactionalClientMethod,
            enumClass: $enumClass,
            transactional: $transactional,
            created: $state['created'],
            skipped: $state['skipped'],
            overwritten: $state['overwritten'],
            intended: $state['intended'],
            warnings: $state['warnings'],
            manualUpdates: $state['manual_updates'],
            errors: $state['errors'],
        );
    }

    private function prepareClientFile(
        string $path,
        string $clientClass,
        TalktoScaffoldNames $names,
        string $namespace,
        bool $dryRun,
        bool $transactional,
        array &$state,
    ): void {
        $methods = $this->clientMethods($names, $transactional);

        if (! is_file($path)) {
            $this->writePreparedFile(new TalktoScaffoldFile(
                $path,
                $this->renderStub('outgoing-client.stub', [
                    'namespace' => $namespace,
                    'class' => $clientClass,
                    'client_method' => rtrim(implode(PHP_EOL, $methods)),
                ]),
            ), $dryRun, $state);

            return;
        }

        $content = $this->readFile($path);

        $missingMethods = [];

        foreach ($methods as $methodName => $method) {
            if (! $this->clientMethodExists($content, (string) $methodName)) {
                $missingMethods[$methodName] = $method;
            }
        }

        if ($missingMethods === []) {
            $state['skipped'][] = $path;

            return;
        }

        if (! str_contains($content, self::CLIENT_METHOD_MARKER)) {
            $message = "Client file [{$path}] is missing the Talkto outgoing methods marker. Add the method manually.";
            $state['warnings'][] = $message;
            $state['manual_updates'][] = $message;
            $state['skipped'][] = $path;

            return;
        }

        if ($dryRun) {
            $state['intended'][] = $path.' (insert client method'.(count($missingMethods) === 1 ? '' : 's').' '.implode(', ', array_keys($missingMethods)).')';

            return;
        }

        $this->mergeWriteResult($state, $this->writer->write([
            new TalktoScaffoldFile($path, $this->insertBeforeMarker($content, self::CLIENT_METHOD_MARKER, rtrim(implode(PHP_EOL, $missingMethods)))),
        ], force: true));
    }

    private function prepareEnumFile(
        string $path,
        string $enumClass,
        TalktoScaffoldNames $names,
        string $namespace,
        bool $dryRun,
        array &$state,
    ): void {
        $case = $this->renderEnumCase($names);

        if (! is_file($path)) {
            $this->writePreparedFile(new TalktoScaffoldFile(
                $path,
                $this->renderStub('outgoing-command-enum.stub', [
                    'namespace' => $namespace,
                    'class' => $enumClass,
                    'enum_case' => rtrim($case),
                ]),
            ), $dryRun, $state);

            return;
        }

        $content = $this->readFile($path);

        if ($this->enumCaseExists($content, (string) $names->commandEnumCase, (string) $names->outgoingCommandValue)) {
            $state['skipped'][] = $path;

            return;
        }

        if (! str_contains($content, self::ENUM_CASE_MARKER)) {
            $message = "Outgoing command enum [{$path}] is missing the Talkto outgoing command cases marker. Add the case manually.";
            $state['warnings'][] = $message;
            $state['manual_updates'][] = $message;
            $state['skipped'][] = $path;

            return;
        }

        if ($dryRun) {
            $state['intended'][] = $path.' (insert enum case '.$names->commandEnumCase.')';

            return;
        }

        $this->mergeWriteResult($state, $this->writer->write([
            new TalktoScaffoldFile($path, $this->insertBeforeMarker($content, self::ENUM_CASE_MARKER, rtrim($case))),
        ], force: true));
    }

    private function writePreparedFile(TalktoScaffoldFile $file, bool $dryRun, array &$state): void
    {
        $this->mergeWriteResult($state, $this->writer->write([$file], dryRun: $dryRun));
    }

    private function renderClientMethod(TalktoScaffoldNames $names): string
    {
        return $this->renderer->render(
            <<<'STUB'
    public function {{ method }}(mixed $source): TalktoMessage
    {
        return app(Commands\{{ command_studly }}\{{ send_class }}::class)
            ->handle($source);
    }

STUB,
            [
                'method' => $names->outgoingClientMethod,
                'command_studly' => $names->commandStudly,
                'send_class' => $names->outgoingSendClass,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function clientMethods(TalktoScaffoldNames $names, bool $transactional): array
    {
        $methods = [
            (string) $names->outgoingClientMethod => $this->renderClientMethod($names),
        ];

        if ($transactional) {
            $methods[(string) $names->outgoingTransactionalClientMethod] = $this->renderTransactionalClientMethod($names);
        }

        return $methods;
    }

    private function renderTransactionalClientMethod(TalktoScaffoldNames $names): string
    {
        return $this->renderer->render(
            <<<'STUB'
    public function {{ method }}(array $data): TalktoMessage
    {
        return app(Commands\{{ command_studly }}\{{ send_class }}::class)
            ->handleTransactionally($data);
    }

STUB,
            [
                'method' => $names->outgoingTransactionalClientMethod,
                'command_studly' => $names->commandStudly,
                'send_class' => $names->outgoingSendClass,
            ],
        );
    }

    private function renderEnumCase(TalktoScaffoldNames $names): string
    {
        return $this->renderer->render(
            "    case {{ enum_case }} = '{{ command_value }}';\n",
            [
                'enum_case' => $names->commandEnumCase,
                'command_value' => $names->outgoingCommandValue,
            ],
        );
    }

    private function renderStub(string $stub, array $replacements): string
    {
        $path = __DIR__.'/../../../stubs/scaffolding/'.$stub;
        $template = file_get_contents($path);

        if ($template === false) {
            throw new RuntimeException("Unable to read scaffold stub [{$path}].");
        }

        return $this->renderer->render($template, $replacements);
    }

    private function insertBeforeMarker(string $content, string $marker, string $insert): string
    {
        $markerLine = '    '.$marker;

        if (str_contains($content, $markerLine)) {
            return str_replace($markerLine, $insert.PHP_EOL.PHP_EOL.$markerLine, $content);
        }

        return str_replace($marker, $insert.PHP_EOL.PHP_EOL.$marker, $content);
    }

    private function clientMethodExists(string $content, string $method): bool
    {
        return preg_match('/function\s+'.preg_quote($method, '/').'\s*\(/', $content) === 1;
    }

    private function enumCaseExists(string $content, string $case, string $value): bool
    {
        return preg_match('/case\s+'.preg_quote($case, '/').'\s*=/', $content) === 1
            || str_contains($content, "'{$value}'")
            || str_contains($content, '"'.$value.'"');
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException("Unable to read file [{$path}].");
        }

        return $content;
    }

    private function mergeWriteResult(array &$state, TalktoScaffoldResult $result): void
    {
        $state['created'] = array_merge($state['created'], $result->created);
        $state['skipped'] = array_merge($state['skipped'], $result->skipped);
        $state['overwritten'] = array_merge($state['overwritten'], $result->overwritten);
        $state['intended'] = array_merge($state['intended'], $result->intended);
        $state['errors'] = array_merge($state['errors'], $result->errors);
    }
}
