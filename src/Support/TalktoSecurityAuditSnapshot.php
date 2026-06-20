<?php

namespace Mrezdev\LaravelTalkto\Support;

use Carbon\CarbonInterface;

/**
 * Public read-only snapshot returned by security audits.
 */
final readonly class TalktoSecurityAuditSnapshot
{
    /**
     * @param  array<int, TalktoSecurityFinding>  $findings
     */
    public function __construct(
        public bool $ok,
        public array $summary,
        public array $findings,
        public CarbonInterface $checkedAt
    ) {}

    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'summary' => $this->summary,
            'findings' => array_map(
                fn (TalktoSecurityFinding $finding): array => $finding->toArray(),
                $this->findings
            ),
            'checked_at' => $this->checkedAt->toIso8601String(),
        ];
    }
}
