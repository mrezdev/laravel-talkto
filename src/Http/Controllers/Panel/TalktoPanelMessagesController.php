<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelMessageFilters;

class TalktoPanelMessagesController
{
    public function index(
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
    ): JsonResponse {
        $authorizer->authorize();

        $filters = TalktoPanelMessageFilters::fromArray($request->query());
        $perPage = (int) config('talkto.panel.messages.per_page', 25);

        return response()->json([
            'filters' => $filters->toArray(),
            'messages' => $messages->paginate($filters, $perPage)->toArray(),
        ]);
    }

    public function show(
        string $message,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
    ): JsonResponse {
        $authorizer->authorize();

        $talktoMessage = $messages->findMessage($message);

        abort_if($talktoMessage === null, 404);

        return response()->json([
            'message' => $talktoMessage,
            'attempts' => $messages->attemptsFor($talktoMessage)->values(),
            'events' => $messages->eventsFor($talktoMessage)->values(),
            'dead_letter' => $messages->deadLetterFor($talktoMessage),
        ]);
    }
}
