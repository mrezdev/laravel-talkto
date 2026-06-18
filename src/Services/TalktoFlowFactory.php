<?php

namespace Mrezdev\LaravelTalkto\Services;

class TalktoFlowFactory
{
    public function __construct(
        protected TalktoOutgoingMessageFactory $messageFactory
    ) {}

    public function flow(string $name): TalktoFlowBuilder
    {
        $builderClass = $this->builderClass();

        return new $builderClass($name, $this->messageFactory);
    }

    protected function builderClass(): string
    {
        $class = config('talkto.builders.flow', TalktoFlowBuilder::class);

        return is_string($class) && is_a($class, TalktoFlowBuilder::class, true)
            ? $class
            : TalktoFlowBuilder::class;
    }
}
