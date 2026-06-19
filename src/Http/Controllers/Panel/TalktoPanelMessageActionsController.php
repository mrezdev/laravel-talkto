<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActionExecutor;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelJsonPresenter;

class TalktoPanelMessageActionsController
{
    public function retry(
        string $message,
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelActionExecutor $actions,
        TalktoPanelMessageQuery $messages,
    ): JsonResponse|RedirectResponse {
        $authorizer->authorize();
        $talktoMessage = $messages->findMessage($message);

        abort_if($talktoMessage === null, 404);

        $result = $actions->retryMessage($talktoMessage);

        if ($request->expectsJson()) {
            return response()->json($result->toArray(), $result->success ? 200 : 422);
        }

        return redirect()
            ->route($this->routePrefix().'messages.show', ['message' => $talktoMessage->message_id])
            ->with($result->success ? 'talkto_panel_status' : 'talkto_panel_error', $result->message);
    }

    public function trace(
        string $message,
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelActionExecutor $actions,
        TalktoPanelJsonPresenter $presenter,
        TalktoPanelMessageQuery $messages,
    ): JsonResponse|View {
        $authorizer->authorize();
        $talktoMessage = $messages->findMessage($message);

        abort_if($talktoMessage === null, 404);

        $limit = filter_var($request->query('limit', 100), FILTER_VALIDATE_INT);
        $limit = is_int($limit) ? max(1, min(500, $limit)) : 100;
        $includePayload = $request->boolean('payload');
        $snapshot = $actions->traceMessage($talktoMessage, $includePayload, $limit);
        $data = [
            'message' => $talktoMessage,
            'trace' => $snapshot,
            'trace_data' => $snapshot->toArray(),
            'payload_requested' => $includePayload,
        ];

        if ($request->expectsJson()) {
            return response()->json($presenter->trace(
                $snapshot->toArray(),
                $includePayload && (bool) config('talkto.panel.messages.show_payload', false)
            ));
        }

        return view('talkto::panel.messages.trace', $data);
    }

    private function routePrefix(): string
    {
        $name = config('talkto.panel.route.name', 'talkto.panel.');

        return is_string($name) && $name !== '' ? $name : 'talkto.panel.';
    }
}
