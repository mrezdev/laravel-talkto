<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoOutgoingScaffolder;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoOutgoingScaffoldResult;
use Throwable;

class MakeTalktoOutgoingCommand extends Command
{
    protected $signature = 'talkto:make-outgoing
        {service : Target service name, for example inventory}
        {talktoCommand : Short command name, for example verify-invoice}
        {--force : Overwrite command-specific generated files when they already exist}
        {--dry-run : Show intended files without writing them}
        {--transactional : Generate source-side transactional outgoing scaffolding}
        {--base-path=app/Talkto : Host app base path for generated files}
        {--base-namespace=App\\Talkto : Host app base namespace for generated classes}';

    protected $description = 'Generate normal outgoing Talkto scaffolding for a target service command.';

    public function handle(TalktoOutgoingScaffolder $scaffolder): int
    {
        try {
            $result = $scaffolder->scaffold(
                service: (string) $this->argument('service'),
                command: (string) $this->argument('talktoCommand'),
                dryRun: (bool) $this->option('dry-run'),
                force: (bool) $this->option('force'),
                transactional: (bool) $this->option('transactional'),
                basePath: (string) $this->option('base-path'),
                baseNamespace: (string) $this->option('base-namespace'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error('Unable to generate outgoing Talkto scaffolding: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->renderResult($result, (bool) $this->option('dry-run'));

        return $result->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    private function renderResult(TalktoOutgoingScaffoldResult $result, bool $dryRun): void
    {
        $this->line('Talkto outgoing scaffold');
        $this->line('service='.$result->names->serviceKebab);
        $this->line('command='.$result->names->commandKebab);
        $this->line('command_value='.$result->names->outgoingCommandValue);
        $this->line('transactional='.($result->transactional ? 'true' : 'false'));
        $this->line('client='.$result->clientFqn);
        $this->line('method='.$result->clientMethod);

        if ($result->transactional) {
            $this->line('transactional_method='.$result->transactionalClientMethod);
        }

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
