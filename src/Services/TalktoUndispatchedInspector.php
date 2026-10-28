<?php

namespace Mrezdev\LaravelTalkto\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Mrezdev\LaravelTalkto\Support\TalktoModelResolver;
use Throwable;

/**
 * @internal Read-only observations; existing rows cannot prove queue publication.
 */
class TalktoUndispatchedInspector
{
    public function __construct(
        private readonly TalktoModelResolver $models,
        private readonly TalktoCurrentServiceGuard $guard,
    ) {}

    public function inspect(?string $direction = null, ?string $messageId = null, int $minimumAgeMinutes = 30, int $limit = 100): array
    {
        if (($direction !== null && ! in_array($direction, ['incoming', 'outgoing'], true))
            || $minimumAgeMinutes < 0 || $minimumAgeMinutes > 525600 || $limit < 1 || $limit > 1000
            || ($messageId !== null && ($messageId === '' || ! mb_check_encoding($messageId, 'UTF-8') || mb_strlen($messageId, 'UTF-8') > 100))) {
            throw new InvalidArgumentException('Invalid diagnostic filters. Use incoming/outgoing, age 0..525600, limit 1..1000, and a nonempty UTF-8 message ID up to 100 characters.');
        }

        $class = $this->models->message();
        $model = new $class;
        $key = $model->getKeyName();
        if (! preg_match('/^[\p{L}\p{N}_]+$/uD', $key)
            || in_array(strtolower($key), ['payload', 'last_response', 'last_error', 'locked_by', 'payload_hash', 'idempotency_key', 'idempotency_fingerprint', 'business_key'], true)) {
            throw new InvalidArgumentException('Sensitive or wide message attributes cannot be diagnostic primary keys.');
        }
        $service = $this->guard->currentService();
        $now = now();
        $column = fn (string $name): string => $model->qualifyColumn($name);
        $query = $model->newQuery()->where(function ($query) use ($service, $column): void {
            $query->where(function ($query) use ($service, $column): void {
                $query->where($column('direction'), 'outgoing')->where($column('source_service'), $service);
            })->orWhere(function ($query) use ($service, $column): void {
                $query->where($column('direction'), 'incoming')->where($column('target_service'), $service);
            });
        })->where($column('created_at'), '<=', $now->copy()->subMinutes($minimumAgeMinutes));

        if ($direction !== null) {
            $query->where($column('direction'), $direction);
        }
        if ($messageId !== null) {
            $query->where($column('message_id'), $messageId);
        } else {
            $query->where(function ($query) use ($column): void {
                $query->where(function ($query) use ($column): void {
                    $query->where($column('direction'), 'outgoing')->where($column('overall_status'), 'waiting_to_send');
                })->orWhere(function ($query) use ($column): void {
                    $query->where($column('direction'), 'incoming')->where($column('overall_status'), 'queued');
                });
            });
        }

        // Apply scope filters before replacing projections and ordering. No
        // model, payload, response, error, event meta, or claim token is hydrated.
        $base = $query->toBase();
        if ($base->groups || $base->havings || $base->unions) {
            throw new InvalidArgumentException('Grouped or union model scopes are not supported by undispatched diagnostics.');
        }
        $fields = array_unique([$key, 'message_id', 'direction', 'source_service', 'target_service',
            'overall_status', 'transport_status', 'destination_action_status', 'created_at', 'locked_at', 'next_retry_at', 'next_attempt_at']);
        $alias = function (string $name) use ($fields): string {
            while (in_array($name, $fields, true)) {
                $name .= '_';
            }

            return $name;
        };
        $lockAlias = $alias('_talkto_lock_present');
        $claimAlias = $alias('_talkto_claim_present');
        $lock = $base->getGrammar()->wrap($column('locked_by'));
        $rows = $base->reorder()->select(array_map($column, $fields))->distinct()
            ->selectRaw("case when {$lock} is not null and {$lock} <> '' then 1 else 0 end as ".$base->getGrammar()->wrap($lockAlias))
            ->selectRaw("case when {$lock} like ? then 1 else 0 end as ".$base->getGrammar()->wrap($claimAlias), ['dispatch-claim:%'])
            ->orderBy($column('created_at'))->orderBy($model->getQualifiedKeyName())->limit($limit)->get();

        $candidates = [];
        foreach ($rows as $row) {
            // SQL collations can equate case-distinct service names. Apply the
            // same exact ownership comparison as the runtime service guard.
            $owned = match ($row->direction) {
                'outgoing' => (string) $row->source_service === $service,
                'incoming' => (string) $row->target_service === $service,
                default => false,
            };
            if (! $owned) {
                continue;
            }
            $state = (string) $row->overall_status;
            $classification = 'ambiguous';
            $reason = 'publication_unproven_may_be_intentional_or_already_queued';
            if (in_array($state, ['completed', 'succeeded', 'failed_final'], true)) {
                $classification = 'terminal';
                $reason = 'terminal_state';
            } elseif ($row->locked_at !== null || (bool) $row->{$lockAlias}) {
                $classification = 'existing_lock';
                $reason = 'existing_lock_activity_unknown';
            } elseif ($row->next_retry_at !== null || $row->next_attempt_at !== null) {
                $classification = 'existing_schedule';
                $reason = 'existing_retry_or_attempt_schedule';
            } elseif (! (($row->direction === 'outgoing' && $state === 'waiting_to_send' && $row->transport_status === 'pending')
                || ($row->direction === 'incoming' && $state === 'queued' && $row->destination_action_status === 'queued'))) {
                $classification = 'other_state';
                $reason = 'not_an_initial_dispatch_state';
            }

            try {
                $age = max(0, (int) Carbon::parse($row->created_at)->diffInMinutes($now));
            } catch (Throwable) {
                $age = null;
            }
            $candidates[] = [
                'database_id' => $this->safeText($row->{$key}),
                'message_id' => $this->safeText($row->message_id),
                'direction' => $this->safeText($row->direction),
                'source_service' => $this->safeText($row->source_service),
                'target_service' => $this->safeText($row->target_service),
                'overall_status' => $this->safeText($state),
                'transport_status' => $this->safeText($row->transport_status),
                'destination_action_status' => $this->safeText($row->destination_action_status),
                'age_minutes' => $age,
                'dispatch_intent' => 'unproven',
                'publication' => 'unknown',
                'classification' => $classification,
                'lock_present' => $row->locked_at !== null || (bool) $row->{$lockAlias},
                'dispatch_claim_present' => (bool) $row->{$claimAlias},
                'claim_activity' => 'unknown',
                'recovery_eligible' => false,
                'reason' => $reason,
            ];
        }

        return [
            'mode' => 'diagnostic_only',
            'recovery_enabled' => false,
            'execution_blocked' => true,
            'service' => $this->safeText($service),
            'count' => count($candidates),
            'limit' => $limit,
            'minimum_age_minutes' => $minimumAgeMinutes,
            'candidates' => $candidates,
            'notice' => 'Snapshot only. Queue absence and dispatch intent are unproven. No message is authorized for recovery.',
        ];
    }

    private function safeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr(preg_replace('/\p{C}/u', '', mb_scrub((string) $value, 'UTF-8')) ?? '', 0, 191);
    }
}
