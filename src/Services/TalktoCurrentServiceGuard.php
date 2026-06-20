<?php

namespace Mrezdev\LaravelTalkto\Services;

use Mrezdev\LaravelTalkto\Models\TalktoMessage;

/**
 * @internal Runtime guard for package-owned current-service scoping.
 */
class TalktoCurrentServiceGuard
{
    public function currentService(): string
    {
        $service = config('talkto.service', 'app');

        return is_string($service) && trim($service) !== ''
            ? trim($service)
            : 'app';
    }

    public function shouldEnforceStorageScope(): bool
    {
        return (bool) config('talkto.storage.enforce_current_service', true);
    }

    public function shouldScopePanelToCurrentService(): bool
    {
        return (bool) config('talkto.panel.scope.current_service_only', true);
    }

    public function ownsOutgoing(TalktoMessage $message): bool
    {
        return $message->direction === 'outgoing'
            && (string) $message->source_service === $this->currentService();
    }

    public function ownsIncoming(TalktoMessage $message): bool
    {
        return $message->direction === 'incoming'
            && (string) $message->target_service === $this->currentService();
    }

    public function owns(TalktoMessage $message): bool
    {
        return $this->ownsOutgoing($message) || $this->ownsIncoming($message);
    }

    public function involvesCurrentService(TalktoMessage $message): bool
    {
        $currentService = $this->currentService();

        return (string) $message->source_service === $currentService
            || (string) $message->target_service === $currentService;
    }

    public function allowsOutgoingProcessing(TalktoMessage $message): bool
    {
        return ! $this->shouldEnforceStorageScope() || $this->ownsOutgoing($message);
    }

    public function allowsIncomingProcessing(TalktoMessage $message): bool
    {
        return ! $this->shouldEnforceStorageScope() || $this->ownsIncoming($message);
    }

    public function allowsProcessing(TalktoMessage $message): bool
    {
        return ! $this->shouldEnforceStorageScope() || $this->owns($message);
    }

    public function logContext(TalktoMessage $message, string $consumer): array
    {
        return [
            'consumer' => $consumer,
            'current_service' => $this->currentService(),
            'database_id' => $message->getKey(),
            'message_id' => $message->message_id,
            'direction' => $message->direction,
            'source_service' => $message->source_service,
            'target_service' => $message->target_service,
        ];
    }
}
