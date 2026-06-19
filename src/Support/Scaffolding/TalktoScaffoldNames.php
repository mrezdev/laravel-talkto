<?php

namespace Mrezdev\LaravelTalkto\Support\Scaffolding;

final readonly class TalktoScaffoldNames
{
    public function __construct(
        public string $originalService,
        public string $serviceKebab,
        public string $serviceStudly,
        public string $serviceCamel,
        public string $serviceVariable,
        public string $serviceNamespaceSegment,
        public ?string $originalCommand = null,
        public ?string $commandValue = null,
        public ?string $commandKebab = null,
        public ?string $commandShortKebab = null,
        public ?string $commandStudly = null,
        public ?string $commandCamel = null,
        public ?string $commandEnumCase = null,
        public ?string $outgoingCommandValue = null,
        public ?string $outgoingSendClass = null,
        public ?string $outgoingPayloadBuilderClass = null,
        public ?string $outgoingTransactionalSourceActionClass = null,
        public ?string $outgoingClientMethod = null,
        public ?string $outgoingTransactionalClientMethod = null,
        public ?string $incomingHandlerClass = null,
        public ?string $incomingActionClass = null,
        public ?string $incomingValidatorClass = null,
    ) {}

    public function toArray(): array
    {
        return [
            'original_service' => $this->originalService,
            'service_kebab' => $this->serviceKebab,
            'service_studly' => $this->serviceStudly,
            'service_camel' => $this->serviceCamel,
            'service_variable' => $this->serviceVariable,
            'service_namespace_segment' => $this->serviceNamespaceSegment,
            'original_command' => $this->originalCommand,
            'command_value' => $this->commandValue,
            'command_kebab' => $this->commandKebab,
            'command_short_kebab' => $this->commandShortKebab,
            'command_studly' => $this->commandStudly,
            'command_camel' => $this->commandCamel,
            'command_enum_case' => $this->commandEnumCase,
            'outgoing_command_value' => $this->outgoingCommandValue,
            'outgoing_send_class' => $this->outgoingSendClass,
            'outgoing_payload_builder_class' => $this->outgoingPayloadBuilderClass,
            'outgoing_transactional_source_action_class' => $this->outgoingTransactionalSourceActionClass,
            'outgoing_client_method' => $this->outgoingClientMethod,
            'outgoing_transactional_client_method' => $this->outgoingTransactionalClientMethod,
            'incoming_handler_class' => $this->incomingHandlerClass,
            'incoming_action_class' => $this->incomingActionClass,
            'incoming_validator_class' => $this->incomingValidatorClass,
        ];
    }
}
