<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelMessageFilters;

class TalktoPanelMessagesController
{
    public function index(
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
    ): JsonResponse|View {
        $authorizer->authorize();

        $filters = TalktoPanelMessageFilters::fromArray($request->query());
        $perPage = (int) config('talkto.panel.messages.per_page', 25);
        $paginator = $messages->paginate($filters, $perPage);

        $data = [
            'filters' => $filters->toArray(),
            'messages' => $paginator,
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'filters' => $data['filters'],
                'messages' => $paginator->toArray(),
            ]);
        }

        return view('talkto::panel.messages.index', $data);
    }

    public function show(
        Request $request,
        string $message,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
    ): JsonResponse|View {
        $authorizer->authorize();

        $talktoMessage = $messages->findMessage($message);

        abort_if($talktoMessage === null, 404);

        $data = [
            'message' => $talktoMessage,
            'attempts' => $messages->attemptsFor($talktoMessage)->values(),
            'events' => $messages->eventsFor($talktoMessage)->values(),
            'dead_letter' => $messages->deadLetterFor($talktoMessage),
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return view('talkto::panel.messages.show', $data);
    }
}
