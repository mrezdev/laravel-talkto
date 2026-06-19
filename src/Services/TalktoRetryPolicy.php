<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Support\TalktoRetryDecision;

class TalktoRetryPolicy
{
    public function settingsFor(TalktoMessage $message): array
    {
        $base = [
            'enabled' => (bool) config('talkto.retry.enabled', true),
            'max_attempts' => $this->positiveInt(config('talkto.retry.max_attempts', 5), 5),
            'backoff_seconds' => $this->backoffSequence(config('talkto.retry.backoff_seconds', [10, 30, 60, 120, 300])),
            'outgoing_enabled' => (bool) config('talkto.retry.outgoing_enabled', true),
            'incoming_enabled' => (bool) config('talkto.retry.incoming_enabled', false),
            'retryable_statuses' => $this->stringList(config('talkto.retry.retryable_statuses', ['failed_retryable']), ['failed_retryable']),
            'final_failure_status' => $this->stringOrDefault(config('talkto.retry.final_failure_status', 'failed_final'), 'failed_final'),
            'retryable_http_statuses' => $this->intList(config('talkto.retry.retryable_http_statuses', [408, 425, 429]), [408, 425, 429]),
            'retry_server_errors' => (bool) config('talkto.retry.retry_server_errors', true),
            'jitter_seconds' => max(0, (int) config('talkto.retry.jitter_seconds', 0)),
        ];

        $settings = $this->mergeSettings($base, $this->directionSettings($message));
        $settings = $this->mergeSettings($settings, $this->peerSettings($message));
        $settings = $this->mergeSettings($settings, $this->commandSettings($message));

        return $this->normalizeSettings($settings);
    }

    public function decisionFor(TalktoMessage $message, array $context = []): TalktoRetryDecision
    {
        $settings = $this->settingsFor($message);
        $currentRetryCount = (int) ($message->retry_count ?? 0);
        $maxAttempts = $this->maxAttempts($message);
        $directionEnabled = $this->isDirectionEnabled($message);
        $statusRetryable = $this->hasRetryableStatus($message);
        $httpStatus = array_key_exists('http_status', $context) ? $context['http_status'] : null;
        $httpStatusRetryable = is_int($httpStatus) ? $this->isRetryableHttpStatus($httpStatus, $message) : false;
        $canSchedule = $directionEnabled && ($currentRetryCount + 1) < $maxAttempts;
        $retryable = $directionEnabled && $statusRetryable && $currentRetryCount < $maxAttempts;
        $reason = 'eligible';

        if (! (bool) ($settings['enabled'] ?? true)) {
            $reason = 'retry_disabled';
            $retryable = false;
            $canSchedule = false;
        } elseif (! $directionEnabled) {
            $reason = 'direction_disabled';
            $retryable = false;
            $canSchedule = false;
        } elseif (is_int($httpStatus) && ! $httpStatusRetryable) {
            $reason = 'non_retryable_status';
            $retryable = false;
            $canSchedule = false;
        } elseif (! $statusRetryable && ! (bool) ($context['ignore_status'] ?? false)) {
            $reason = 'non_retryable_status';
            $retryable = false;
        } elseif ($currentRetryCount >= $maxAttempts) {
            $reason = 'max_attempts_exhausted';
            $retryable = false;
            $canSchedule = false;
        } elseif (($message->next_retry_at ?? null) !== null && ! $this->isDue($message)) {
            $reason = 'not_due';
            $retryable = false;
        }

        return new TalktoRetryDecision(
            $retryable,
            $canSchedule,
            $reason,
            $directionEnabled,
            $statusRetryable,
            $httpStatusRetryable,
            $currentRetryCount,
            $maxAttempts,
            $this->backoffSeconds($currentRetryCount, $message),
            optional($message->next_retry_at)->toIso8601String(),
            $this->finalFailureStatus($message),
            $settings,
        );
    }

    public function backoffSeconds(int $retryCount, ?TalktoMessage $message = null): int
    {
        $backoff = $message
            ? $this->settingsFor($message)['backoff_seconds']
            : $this->backoffSequence(config('talkto.retry.backoff_seconds', [10, 30, 60, 120, 300]));
        $index = max(0, min($retryCount, count($backoff) - 1));

        $seconds = max(0, (int) $backoff[$index]);
        $jitter = $message ? (int) ($this->settingsFor($message)['jitter_seconds'] ?? 0) : max(0, (int) config('talkto.retry.jitter_seconds', 0));

        return $seconds + ($jitter > 0 ? random_int(0, $jitter) : 0);
    }

    public function retryableStatuses(): array
    {
        return $this->stringList(config('talkto.retry.retryable_statuses', ['failed_retryable']), ['failed_retryable']);
    }

    public function finalFailureStatus(?TalktoMessage $message = null): string
    {
        return $message
            ? $this->settingsFor($message)['final_failure_status']
            : $this->stringOrDefault(config('talkto.retry.final_failure_status', 'failed_final'), 'failed_final');
    }

    public function isRetryableHttpStatus(?int $status, ?TalktoMessage $message = null): bool
    {
        if ($status === null) {
            return false;
        }

        $settings = $message ? $this->settingsFor($message) : $this->settingsForGlobal();
        $retryableStatuses = $settings['retryable_http_statuses'];

        if (in_array($status, $retryableStatuses, true)) {
            return true;
        }

        return (bool) ($settings['retry_server_errors'] ?? true)
            && $status >= 500
            && $status <= 599;
    }

    public function maxAttempts(TalktoMessage $message): int
    {
        $messageMaxAttempts = (int) ($message->max_attempts ?? 0);

        if ($messageMaxAttempts > 0) {
            return $messageMaxAttempts;
        }

        return $this->settingsFor($message)['max_attempts'];
    }

    public function isDirectionEnabled(TalktoMessage $message): bool
    {
        $settings = $this->settingsFor($message);

        if (! (bool) ($settings['enabled'] ?? true)) {
            return false;
        }

        if ($message->direction === 'outgoing') {
            return (bool) ($settings['outgoing_enabled'] ?? true);
        }

        if ($message->direction === 'incoming') {
            return (bool) ($settings['incoming_enabled'] ?? false);
        }

        return false;
    }

    public function canScheduleRetry(TalktoMessage $message): bool
    {
        return $this->isDirectionEnabled($message)
            && (((int) ($message->retry_count ?? 0)) + 1) < $this->maxAttempts($message);
    }

    public function canRetry(TalktoMessage $message): bool
    {
        return $this->isDirectionEnabled($message)
            && $this->hasRetryableStatus($message)
            && ((int) ($message->retry_count ?? 0)) < $this->maxAttempts($message);
    }

    public function isDue(TalktoMessage $message): bool
    {
        if ($message->next_retry_at === null) {
            return false;
        }

        return $message->next_retry_at->lessThanOrEqualTo(now());
    }

    public function markRetryableFailure(
        TalktoMessage $message,
        string $statusColumn,
        ?string $errorMessage = null,
        ?int $httpStatus = null
    ): TalktoMessage {
        $currentRetryCount = (int) ($message->retry_count ?? 0);
        $backoffSeconds = $this->backoffSeconds($currentRetryCount, $message);
        $nextRetryAt = now()->addSeconds($backoffSeconds);

        $message->forceFill(array_filter([
            $statusColumn => 'failed',
            'overall_status' => 'failed_retryable',
            'retry_count' => $currentRetryCount + 1,
            'next_retry_at' => $nextRetryAt,
            'next_attempt_at' => $nextRetryAt,
            'last_attempted_at' => now(),
            'last_http_status' => $httpStatus,
            'last_error' => $this->excerpt($errorMessage),
            'failed_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
        ], fn (mixed $value): bool => $value !== null))->save();

        return $message;
    }

    public function markFinalFailure(
        TalktoMessage $message,
        string $statusColumn,
        ?string $errorMessage = null,
        ?int $httpStatus = null
    ): TalktoMessage {
        $finalStatus = $this->finalFailureStatus($message);
        $attributes = [
            $statusColumn => $finalStatus,
            'overall_status' => $finalStatus,
            'next_retry_at' => null,
            'next_attempt_at' => null,
            'last_attempted_at' => now(),
            'last_http_status' => $httpStatus,
            'last_error' => $this->excerpt($errorMessage),
            'failed_at' => now(),
            'locked_at' => null,
            'locked_by' => null,
        ];

        if ($httpStatus === null) {
            unset($attributes['last_http_status']);
        }

        $message->forceFill($attributes)->save();

        return $message;
    }

    private function hasRetryableStatus(TalktoMessage $message): bool
    {
        $statuses = $this->settingsFor($message)['retryable_statuses'];

        return in_array($message->overall_status, $statuses, true)
            || in_array($message->transport_status, $statuses, true)
            || in_array($message->destination_action_status, $statuses, true);
    }

    private function excerpt(?string $value, int $limit = 2000): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }

    private function settingsForGlobal(): array
    {
        return $this->normalizeSettings([
            'enabled' => (bool) config('talkto.retry.enabled', true),
            'max_attempts' => $this->positiveInt(config('talkto.retry.max_attempts', 5), 5),
            'backoff_seconds' => $this->backoffSequence(config('talkto.retry.backoff_seconds', [10, 30, 60, 120, 300])),
            'outgoing_enabled' => (bool) config('talkto.retry.outgoing_enabled', true),
            'incoming_enabled' => (bool) config('talkto.retry.incoming_enabled', false),
            'retryable_statuses' => $this->retryableStatuses(),
            'final_failure_status' => $this->finalFailureStatus(),
            'retryable_http_statuses' => $this->intList(config('talkto.retry.retryable_http_statuses', [408, 425, 429]), [408, 425, 429]),
            'retry_server_errors' => (bool) config('talkto.retry.retry_server_errors', true),
            'jitter_seconds' => max(0, (int) config('talkto.retry.jitter_seconds', 0)),
        ]);
    }

    private function directionSettings(TalktoMessage $message): array
    {
        $directions = config('talkto.retry.directions', []);
        $direction = (string) ($message->direction ?? '');

        if (! is_array($directions) || ! isset($directions[$direction]) || ! is_array($directions[$direction])) {
            return [];
        }

        $settings = $directions[$direction];

        if (array_key_exists('enabled', $settings)) {
            $settings[$direction === 'incoming' ? 'incoming_enabled' : 'outgoing_enabled'] = (bool) $settings['enabled'];
        }

        return $settings;
    }

    private function peerSettings(TalktoMessage $message): array
    {
        $targets = config('talkto.retry.targets', []);
        $peer = $message->direction === 'incoming'
            ? (string) ($message->source_service ?? '')
            : (string) ($message->target_service ?? '');

        return is_array($targets) && isset($targets[$peer]) && is_array($targets[$peer])
            ? $targets[$peer]
            : [];
    }

    private function commandSettings(TalktoMessage $message): array
    {
        $commands = config('talkto.retry.commands', []);
        $command = (string) ($message->command ?? '');

        if (! is_array($commands)) {
            return [];
        }

        if (isset($commands[$command]) && is_array($commands[$command])) {
            return $commands[$command];
        }

        $nested = data_get($commands, $command);

        return is_array($nested) ? $nested : [];
    }

    private function mergeSettings(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value !== null) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function normalizeSettings(array $settings): array
    {
        $settings['max_attempts'] = $this->positiveInt($settings['max_attempts'] ?? null, 5);
        $settings['backoff_seconds'] = $this->backoffSequence($settings['backoff_seconds'] ?? null);
        $settings['retryable_statuses'] = $this->stringList($settings['retryable_statuses'] ?? null, ['failed_retryable']);
        $settings['final_failure_status'] = $this->stringOrDefault($settings['final_failure_status'] ?? null, 'failed_final');
        $settings['retryable_http_statuses'] = $this->intList($settings['retryable_http_statuses'] ?? null, [408, 425, 429]);
        $settings['retry_server_errors'] = (bool) ($settings['retry_server_errors'] ?? true);
        $settings['enabled'] = (bool) ($settings['enabled'] ?? true);
        $settings['outgoing_enabled'] = (bool) ($settings['outgoing_enabled'] ?? true);
        $settings['incoming_enabled'] = (bool) ($settings['incoming_enabled'] ?? false);
        $settings['jitter_seconds'] = max(0, (int) ($settings['jitter_seconds'] ?? 0));

        return $settings;
    }

    private function backoffSequence(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [10];
        }

        $sequence = array_values(array_map(fn (mixed $seconds): int => max(0, (int) $seconds), $value));

        return $sequence === [] ? [10] : $sequence;
    }

    private function intList(mixed $value, array $default): array
    {
        if (! is_array($value) || $value === []) {
            return $default;
        }

        $values = array_values(array_unique(array_map('intval', $value)));

        return $values === [] ? $default : $values;
    }

    private function stringList(mixed $value, array $default): array
    {
        if (! is_array($value) || $value === []) {
            return $default;
        }

        $values = array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''));

        return $values === [] ? $default : $values;
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $value = (int) $value;

        return $value > 0 ? $value : $default;
    }
}
