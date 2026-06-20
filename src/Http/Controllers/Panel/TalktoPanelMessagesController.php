<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelJsonPresenter;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelMessageFilters;

/**
 * @internal Optional panel controller; use panel routes/views rather than this class.
 */
class TalktoPanelMessagesController
{
    public function index(
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
        TalktoPanelJsonPresenter $presenter,
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
            $messagesArray = $paginator->toArray();
            $messagesArray['data'] = $presenter->messages($paginator->items());

            return response()->json([
                'filters' => $data['filters'],
                'messages' => $messagesArray,
            ]);
        }

        return $this->renderView('talkto::panel.messages.index', $data);
    }

    public function show(
        Request $request,
        string $message,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
        TalktoPanelJsonPresenter $presenter,
    ): JsonResponse|View {
        $authorizer->authorize();

        $talktoMessage = $messages->findMessage($message);

        abort_if($talktoMessage === null, 404);

        $data = [
            'message' => $talktoMessage,
            'message_data' => $presenter->message($talktoMessage),
            'attempts' => $messages->attemptsFor($talktoMessage)->values(),
            'events' => $messages->eventsFor($talktoMessage)->values(),
            'dead_letter' => $messages->deadLetterFor($talktoMessage),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $presenter->message($talktoMessage),
                'attempts' => $presenter->attempts($data['attempts']),
                'events' => $presenter->events($data['events']),
                'dead_letter' => $presenter->deadLetter($data['dead_letter']),
            ]);
        }

        return $this->renderView('talkto::panel.messages.show', $data);
    }

    private function renderView(string $view, array $data): View
    {
        abort_unless(view()->exists($view), 500);

        return view($view, $data);
    }
}
