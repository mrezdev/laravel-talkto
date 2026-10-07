<?php

namespace Mrezdev\LaravelTalkto\Console\Commands;

use Illuminate\Console\Command;
use Mrezdev\LaravelTalkto\Services\TalktoDoctor;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * @internal Local readiness command; the Artisan name is the supported API.
 */
class TalktoDoctorCommand extends Command
{
    protected $signature = 'talkto:doctor {--json : Output JSON without terminal formatting}';

    protected $description = 'Inspect local Talkto readiness without changing data or contacting peers.';

    public function handle(TalktoDoctor $doctor): int
    {
        $result = $doctor->inspect();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } else {
            $this->line('Laravel Talkto Doctor');
            $category = null;

            foreach ($result['checks'] as $check) {
                if ($check['category'] !== $category) {
                    $category = $check['category'];
                    $this->newLine();
                    $this->line(ucwords(str_replace('_', ' ', $category)));
                }

                $text = strtoupper($check['status']).'  '.$check['label'].': '.$check['value'];
                if ($check['message'] !== null) {
                    $text .= ' — '.$check['message'];
                }
                $this->line(OutputFormatter::escape($text));
            }

            $this->newLine();
            $summary = $result['summary'];
            $this->line("Summary: {$summary['pass']} passed, {$summary['warn']} warnings, {$summary['fail']} failed, {$summary['info']} info");
            $this->line($result['status'] === 'ready' ? 'Talkto is ready.' : 'Talkto is not ready.');
        }

        return $result['status'] === 'ready' ? self::SUCCESS : self::FAILURE;
    }
}
