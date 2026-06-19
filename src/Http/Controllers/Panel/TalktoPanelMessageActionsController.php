<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mrezdev\LaravelTalkto\Models\TalktoMessage;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActionExecutor;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;

class TalktoPanelMessageActionsController
{
    public function retry(
        string $message,
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelActionExecutor $actions,
    ): JsonResponse|RedirectResponse {
        $authorizer->authorize();
        $talktoMessage = $this->findMessage($message);

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
    ): JsonResponse|View {
        $authorizer->authorize();
        $talktoMessage = $this->findMessage($message);

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
            return response()->json($snapshot->toArray());
        }

        return view('talkto::panel.messages.trace', $data);
    }

    private function findMessage(string $message): ?TalktoMessage
    {
        $messageClass = $this->messageModelClass();

        if (ctype_digit($message)) {
            $found = $messageClass::query()->whereKey((int) $message)->first();

            if ($found instanceof TalktoMessage) {
                return $found;
            }
        }

        return $messageClass::query()->where('message_id', $message)->first();
    }

    private function messageModelClass(): string
    {
        $class = config('talkto.models.message', TalktoMessage::class);

        return is_string($class) && is_a($class, TalktoMessage::class, true)
            ? $class
            : TalktoMessage::class;
    }

    private function routePrefix(): string
    {
        $name = config('talkto.panel.route.name', 'talkto.panel.');

        return is_string($name) && $name !== '' ? $name : 'talkto.panel.';
    }
}
