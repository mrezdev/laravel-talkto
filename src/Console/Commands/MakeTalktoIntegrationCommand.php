<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoIncomingScaffolder;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoOutgoingScaffolder;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoIncomingScaffoldResult;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoOutgoingScaffoldResult;
use Throwable;

class MakeTalktoIntegrationCommand extends Command
{
    protected $signature = 'talkto:make-integration
        {service : Remote service name, for example inventory}
        {talktoCommand : Command name/value. For outgoing use short name like verify-invoice. For incoming use full value like website.invoice-verified.}
        {--outgoing : Generate outgoing scaffolding}
        {--incoming : Generate incoming scaffolding}
        {--transactional : Generate transactional outgoing scaffolding. Only valid with --outgoing.}
        {--force : Overwrite command-specific generated files when allowed}
        {--dry-run : Show intended files without writing them}
        {--base-path=app/Talkto : Host app base path for generated files}
        {--base-namespace=App\\Talkto : Host app base namespace for generated classes}';

    protected $description = 'Generate Talkto integration scaffolding by delegating to outgoing or incoming generators.';

    public function handle(
        TalktoOutgoingScaffolder $outgoingScaffolder,
        TalktoIncomingScaffolder $incomingScaffolder,
    ): int {
        $outgoing = (bool) $this->option('outgoing');
        $incoming = (bool) $this->option('incoming');
        $transactional = (bool) $this->option('transactional');

        if (! $outgoing && ! $incoming) {
            $this->error('Choose exactly one direction: use --outgoing or --incoming.');

            return self::FAILURE;
        }

        if ($outgoing && $incoming) {
            $this->error('Choose exactly one direction. Do not use --outgoing and --incoming together.');

            return self::FAILURE;
        }

        if ($incoming && $transactional) {
            $this->error('--transactional is only valid with --outgoing.');

            return self::FAILURE;
        }

        try {
            if ($outgoing) {
                $result = $outgoingScaffolder->scaffold(
                    service: (string) $this->argument('service'),
                    command: (string) $this->argument('talktoCommand'),
                    dryRun: (bool) $this->option('dry-run'),
                    force: (bool) $this->option('force'),
                    transactional: $transactional,
                    basePath: (string) $this->option('base-path'),
                    baseNamespace: (string) $this->option('base-namespace'),
                );

                $this->renderOutgoingResult($result, (bool) $this->option('dry-run'));

                return $result->hasErrors() ? self::FAILURE : self::SUCCESS;
            }

            $result = $incomingScaffolder->scaffold(
                service: (string) $this->argument('service'),
                command: (string) $this->argument('talktoCommand'),
                dryRun: (bool) $this->option('dry-run'),
                force: (bool) $this->option('force'),
                basePath: (string) $this->option('base-path'),
                baseNamespace: (string) $this->option('base-namespace'),
            );

            $this->renderIncomingResult($result, (bool) $this->option('dry-run'));

            return $result->hasErrors() ? self::FAILURE : self::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error('Unable to generate Talkto integration scaffolding: '.$throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function renderOutgoingResult(TalktoOutgoingScaffoldResult $result, bool $dryRun): void
    {
        $this->line('Talkto integration scaffold');
        $this->line('mode=outgoing');
        $this->line('target_service='.$result->names->serviceKebab);
        $this->line('command_value='.$result->names->outgoingCommandValue);
        $this->line('transactional='.($result->transactional ? 'true' : 'false'));

        if ($dryRun) {
            $this->line('dry_run=true');
        }

        $this->renderList('Intended files/updates', $result->intended);
        $this->renderList('Created files', $result->created);
        $this->renderList('Skipped files', $result->skipped);
        $this->renderList('Overwritten files', $result->overwritten);

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        $this->renderList('Manual update notes', $result->manualUpdates);
        $this->renderList('Errors', $result->errors);

        $this->line('Example usage:');

        foreach (explode(PHP_EOL, $result->exampleUsage()) as $line) {
            $this->line($line);
        }
    }

    private function renderIncomingResult(TalktoIncomingScaffoldResult $result, bool $dryRun): void
    {
        $this->line('Talkto integration scaffold');
        $this->line('mode=incoming');
        $this->line('source_service='.$result->names->serviceKebab);
        $this->line('command_value='.$result->names->commandValue);
        $this->line('handler='.$result->handlerFqn);

        if ($dryRun) {
            $this->line('dry_run=true');
        }

        $this->renderList('Intended files/updates', $result->intended);
        $this->renderList('Created files', $result->created);
        $this->renderList('Skipped files', $result->skipped);
        $this->renderList('Overwritten files', $result->overwritten);

        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }

        $this->renderList('Manual update notes', $result->manualUpdates);
        $this->renderList('Errors', $result->errors);

        $this->line('Config snippet:');
        $this->line($result->configSnippet);
    }

    private function renderList(string $label, array $items): void
    {
        if ($items === []) {
            $this->line($label.': none');

            return;
        }

        $this->line($label.':');

        foreach ($items as $key => $item) {
            $this->line('- '.(is_string($key) ? "{$key}: {$item}" : $item));
        }
    }
}
