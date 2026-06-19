<?php

namespace Mrezdev\LaravelTalkto\Services\Scaffolding;

use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoIncomingScaffoldResult;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldFile;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldNames;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoScaffoldResult;
use RuntimeException;

final class TalktoIncomingScaffolder
{
    private const ENUM_CASE_MARKER = '// talkto:incoming-command-cases';

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
        string $basePath = 'app/Talkto',
        string $baseNamespace = 'App\\Talkto',
    ): TalktoIncomingScaffoldResult {
        $names = $this->names->resolveIncoming($service, $command);
        $paths = $this->paths->resolveIncoming($service, $command, $basePath, $baseNamespace);
        $enumClass = $names->serviceStudly.'IncomingCommand';
        $handlerFqn = $paths->commandNamespace.'\\'.$names->incomingHandlerClass;
        $state = [
            'created' => [],
            'skipped' => [],
            'overwritten' => [],
            'intended' => [],
            'warnings' => [],
            'manual_updates' => [],
            'errors' => [],
        ];

        $this->prepareEnumFile($paths->file('command_enum'), $enumClass, $names, $paths->serviceNamespace, $dryRun, $state);

        $write = $this->writer->write([
            new TalktoScaffoldFile(
                $paths->file('handler'),
                $this->renderStub('incoming-handler.stub', [
                    'namespace' => $paths->commandNamespace,
                    'class' => $names->incomingHandlerClass,
                    'validator_class' => $names->incomingValidatorClass,
                    'action_class' => $names->incomingActionClass,
                ]),
            ),
            new TalktoScaffoldFile(
                $paths->file('action'),
                $this->renderStub('incoming-action.stub', [
                    'namespace' => $paths->commandNamespace,
                    'class' => $names->incomingActionClass,
                ]),
            ),
            new TalktoScaffoldFile(
                $paths->file('validator'),
                $this->renderStub('incoming-payload-validator.stub', [
                    'namespace' => $paths->commandNamespace,
                    'class' => $names->incomingValidatorClass,
                ]),
            ),
        ], dryRun: $dryRun, force: $force);

        $this->mergeWriteResult($state, $write);

        return new TalktoIncomingScaffoldResult(
            names: $names,
            paths: $paths,
            handlerClass: (string) $names->incomingHandlerClass,
            handlerFqn: $handlerFqn,
            enumClass: $enumClass,
            configSnippet: $this->configSnippet($names, $handlerFqn),
            created: $state['created'],
            skipped: $state['skipped'],
            overwritten: $state['overwritten'],
            intended: $state['intended'],
            warnings: $state['warnings'],
            manualUpdates: $state['manual_updates'],
            errors: $state['errors'],
        );
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
            $this->mergeWriteResult($state, $this->writer->write([
                new TalktoScaffoldFile(
                    $path,
                    $this->renderStub('incoming-command-enum.stub', [
                        'namespace' => $namespace,
                        'class' => $enumClass,
                        'enum_case' => rtrim($case),
                    ]),
                ),
            ], dryRun: $dryRun));

            return;
        }

        $content = $this->readFile($path);

        if ($this->enumCaseExists($content, (string) $names->commandEnumCase, (string) $names->commandValue)) {
            $state['skipped'][] = $path;

            return;
        }

        if (! str_contains($content, self::ENUM_CASE_MARKER)) {
            $message = "Incoming command enum [{$path}] is missing the Talkto incoming command cases marker. Add the case manually.";
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

    private function renderEnumCase(TalktoScaffoldNames $names): string
    {
        return $this->renderer->render(
            "    case {{ enum_case }} = '{{ command_value }}';\n",
            [
                'enum_case' => $names->commandEnumCase,
                'command_value' => $names->commandValue,
            ],
        );
    }

    private function configSnippet(TalktoScaffoldNames $names, string $handlerFqn): string
    {
        return $this->renderer->render(
            <<<'SNIPPET'
'incoming' => [
    '{{ service }}' => [
        'secret' => env('{{ secret_env }}'),

        'allowed_commands' => [
            '{{ command_value }}' => [
                'driver' => 'handler',
                'handler' => {{ handler_fqn }}::class,
                'idempotency' => 'required',
            ],
        ],
    ],
],
SNIPPET,
            [
                'service' => $names->serviceKebab,
                'secret_env' => 'TALKTO_FROM_'.strtoupper(str_replace('-', '_', $names->serviceKebab)).'_SECRET',
                'command_value' => $names->commandValue,
                'handler_fqn' => $handlerFqn,
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
