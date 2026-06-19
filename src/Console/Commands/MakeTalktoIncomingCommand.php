<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Services\Scaffolding\TalktoIncomingScaffolder;
use Mrezdev\LaravelTalkto\Support\Scaffolding\TalktoIncomingScaffoldResult;
use Throwable;

class MakeTalktoIncomingCommand extends Command
{
    protected $signature = 'talkto:make-incoming
        {service : Source service name, for example inventory}
        {talktoCommand : Full incoming command value, for example website.invoice-verified}
        {--force : Overwrite command-specific generated files when they already exist}
        {--dry-run : Show intended files without writing them}
        {--base-path=app/Talkto : Host app base path for generated files}
        {--base-namespace=App\\Talkto : Host app base namespace for generated classes}';

    protected $description = 'Generate incoming Talkto scaffolding for a source service command.';

    public function handle(TalktoIncomingScaffolder $scaffolder): int
    {
        try {
            $result = $scaffolder->scaffold(
                service: (string) $this->argument('service'),
                command: (string) $this->argument('talktoCommand'),
                dryRun: (bool) $this->option('dry-run'),
                force: (bool) $this->option('force'),
                basePath: (string) $this->option('base-path'),
                baseNamespace: (string) $this->option('base-namespace'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $throwable) {
            $this->error('Unable to generate incoming Talkto scaffolding: '.$throwable->getMessage());

            return self::FAILURE;
        }

        $this->renderResult($result, (bool) $this->option('dry-run'));

        return $result->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    private function renderResult(TalktoIncomingScaffoldResult $result, bool $dryRun): void
    {
        $this->line('Talkto incoming scaffold');
        $this->line('source_service='.$result->names->serviceKebab);
        $this->line('command_value='.$result->names->commandValue);
        $this->line('command_class_base='.$result->names->commandStudly);
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
