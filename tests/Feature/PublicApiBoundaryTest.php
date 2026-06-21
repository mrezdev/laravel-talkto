<?php

use Mrezdev\LaravelTalkto\Contracts\CommandHandlerContract;
use Mrezdev\LaravelTalkto\Contracts\IncomingCommandResultContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackReceiverContract;
use Mrezdev\LaravelTalkto\Contracts\ResultCallbackSenderContract;
use Mrezdev\LaravelTalkto\Contracts\SourceActionContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoHttpClient;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingCommandHandler;
use Mrezdev\LaravelTalkto\Contracts\TalktoIncomingHandlerRegistryContract;
use Mrezdev\LaravelTalkto\Contracts\TalktoOutgoingTargetRegistryContract;
use Mrezdev\LaravelTalkto\Data\TalktoEnvelopeData;
use Mrezdev\LaravelTalkto\Data\TalktoHttpResponse;
use Mrezdev\LaravelTalkto\Data\TalktoIncomingCommandResultData;
use Mrezdev\LaravelTalkto\Data\TalktoResultCallbackData;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoReceiveController;
use Mrezdev\LaravelTalkto\Http\Controllers\TalktoResultCallbackController;
use Mrezdev\LaravelTalkto\Jobs\ProcessIncomingTalktoMessage;
use Mrezdev\LaravelTalkto\Jobs\SendTalktoMessage;
use Mrezdev\LaravelTalkto\Pipelines\ProcessIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Pipelines\ReceiveIncomingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Pipelines\SendOutgoingTalktoMessagePipeline;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActionExecutor;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Services\TalktoFlowBuilder;
use Mrezdev\LaravelTalkto\Services\TalktoFlowFactory;
use Mrezdev\LaravelTalkto\Services\TalktoIncomingCommandResult;
use Mrezdev\LaravelTalkto\Services\TalktoOutgoingMessageFactory;
use Mrezdev\LaravelTalkto\Services\TalktoSecurityAuditor;
use Mrezdev\LaravelTalkto\Services\TalktoTraceReporter;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelActionResult;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelJsonPresenter;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelMessageFilters;
use Mrezdev\LaravelTalkto\Support\TalktoSecurityRedactor;

function publicApiBoundaryPath(string $path): string
{
    return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function publicApiBoundaryFile(string $path): string
{
    return file_get_contents(publicApiBoundaryPath($path)) ?: '';
}

function publicApiBoundaryDocComment(string $class): string
{
    return (new ReflectionClass($class))->getDocComment() ?: '';
}

function publicApiBoundaryShortName(string $class): string
{
    return (new ReflectionClass($class))->getShortName();
}

test('public api documentation is linked and names current security defaults', function (): void {
    $publicApi = publicApiBoundaryFile('docs/PUBLIC_API.md');
    $readme = publicApiBoundaryFile('README.md');
    $docsReadme = publicApiBoundaryFile('docs/README.md');

    expect($publicApi)->toContain('mrezdev/laravel-talkto')
        ->and($publicApi)->toContain('composer require mrezdev/laravel-talkto')
        ->and($publicApi)->toContain('TALKTO_SIGNATURE_VERSION=v2')
        ->and($publicApi)->toContain('TALKTO_ACCEPT_SIGNATURE_VERSIONS=v2')
        ->and($publicApi)->toContain('TALKTO_REQUIRE_V2_NONCE=true')
        ->and($publicApi)->toContain('talkto.security.replay_protection.require_nonce_for_v2')
        ->and($publicApi)->not->toContain('talkto.security.require_nonce_for_v2')
        ->and($publicApi)->toContain('v1 is legacy/manual opt-in only')
        ->and($readme)->toContain('docs/PUBLIC_API.md')
        ->and($docsReadme)->toContain('PUBLIC_API.md')
        ->and($docsReadme)->toContain('internal boundary');
});

test('public api documentation names representative public contracts and classes', function (): void {
    $publicApi = publicApiBoundaryFile('docs/PUBLIC_API.md');

    $publicTypes = [
        CommandHandlerContract::class,
        TalktoIncomingCommandHandler::class,
        IncomingCommandResultContract::class,
        SourceActionContract::class,
        TalktoHttpClient::class,
        TalktoIncomingHandlerRegistryContract::class,
        TalktoOutgoingTargetRegistryContract::class,
        ResultCallbackSenderContract::class,
        ResultCallbackReceiverContract::class,
        TalktoEnvelopeData::class,
        TalktoHttpResponse::class,
        TalktoIncomingCommandResultData::class,
        TalktoResultCallbackData::class,
        TalktoIncomingCommandResult::class,
        TalktoOutgoingMessageFactory::class,
        TalktoFlowFactory::class,
        TalktoFlowBuilder::class,
        TalktoTraceReporter::class,
        TalktoSecurityAuditor::class,
        TalktoSecurityRedactor::class,
    ];

    foreach ($publicTypes as $publicType) {
        expect(class_exists($publicType) || interface_exists($publicType))->toBeTrue()
            ->and($publicApi)->toContain(publicApiBoundaryShortName($publicType));
    }
});

test('representative implementation internals are marked internal', function (): void {
    $internalTypes = [
        TalktoReceiveController::class,
        TalktoResultCallbackController::class,
        ProcessIncomingTalktoMessage::class,
        SendTalktoMessage::class,
        ReceiveIncomingTalktoMessagePipeline::class,
        ProcessIncomingTalktoMessagePipeline::class,
        SendOutgoingTalktoMessagePipeline::class,
        TalktoPanelActionExecutor::class,
        TalktoPanelMessageQuery::class,
        TalktoPanelActionResult::class,
        TalktoPanelJsonPresenter::class,
        TalktoPanelMessageFilters::class,
    ];

    foreach ($internalTypes as $internalType) {
        expect(publicApiBoundaryDocComment($internalType))->toContain('@internal');
    }
});

test('representative public api types are not marked internal', function (): void {
    $publicTypes = [
        CommandHandlerContract::class,
        TalktoIncomingCommandHandler::class,
        IncomingCommandResultContract::class,
        SourceActionContract::class,
        TalktoHttpClient::class,
        TalktoIncomingHandlerRegistryContract::class,
        TalktoOutgoingTargetRegistryContract::class,
        ResultCallbackSenderContract::class,
        ResultCallbackReceiverContract::class,
        TalktoIncomingCommandResult::class,
        TalktoOutgoingMessageFactory::class,
        TalktoFlowFactory::class,
        TalktoFlowBuilder::class,
        TalktoTraceReporter::class,
        TalktoSecurityAuditor::class,
        TalktoSecurityRedactor::class,
    ];

    foreach ($publicTypes as $publicType) {
        expect(publicApiBoundaryDocComment($publicType))->not->toContain('@internal');
    }
});
