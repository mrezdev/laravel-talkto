<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Services\TalktoSecurityAuditor;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityAuditSnapshot;

class SecurityAuditTalktoCommand extends Command
{
    protected $signature = 'talkto:security-audit
        {--json : Output JSON}
        {--fail-on= : Exit non-zero on severity: warning, error, critical}';

    protected $description = 'Run a read-only Talkto security configuration audit.';

    public function handle(TalktoSecurityAuditor $auditor): int
    {
        $failOn = $this->validatedFailOn();

        if ($failOn === false) {
            return self::FAILURE;
        }

        $snapshot = $auditor->audit();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHuman($snapshot);
        }

        if (is_string($failOn) && $this->hasFindingAtOrAbove($snapshot, $failOn)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function validatedFailOn(): string|false|null
    {
        $failOn = $this->option('fail-on');

        if ($failOn === null || $failOn === '') {
            return null;
        }

        if (! is_string($failOn) || ! in_array($failOn, ['warning', 'error', 'critical'], true)) {
            $this->error('Invalid --fail-on. Use warning, error, or critical.');

            return false;
        }

        return $failOn;
    }

    private function hasFindingAtOrAbove(TalktoSecurityAuditSnapshot $snapshot, string $threshold): bool
    {
        foreach ($snapshot->findings as $finding) {
            if (TalktoSecurityAuditor::severityMeetsThreshold($finding->severity, $threshold)) {
                return true;
            }
        }

        return false;
    }

    private function renderHuman(TalktoSecurityAuditSnapshot $snapshot): void
    {
        $data = $snapshot->toArray();
        $counts = $data['summary']['severity_counts'];

        $this->line('Talkto security audit');
        $this->line('ok='.($data['ok'] ? 'yes' : 'no').' checked_at='.$data['checked_at']);
        $this->line(
            'findings='.$data['summary']['total_findings']
            .' critical='.$counts['critical']
            .' error='.$counts['error']
            .' warning='.$counts['warning']
            .' info='.$counts['info']
        );

        if ($data['findings'] === []) {
            $this->info('No findings.');

            return;
        }

        $this->table(
            ['severity', 'code', 'message', 'recommendation'],
            array_map(fn (array $finding): array => [
                $finding['severity'],
                $finding['code'],
                $finding['message'],
                $finding['recommendation'],
            ], $data['findings'])
        );
    }
}
