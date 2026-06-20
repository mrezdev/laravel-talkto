<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelJsonPresenter;

/**
 * @internal Optional panel controller; use panel routes/views rather than this class.
 */
class TalktoPanelController
{
    public function index(
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
        TalktoPanelConnectionHealthChecker $healthChecker,
        TalktoPanelJsonPresenter $presenter,
    ): JsonResponse|View {
        $authorizer->authorize();

        $data = [
            'latest_messages' => $messages->latest(10)->values(),
            'connections_health' => $healthChecker
                ->checkAll((int) config('talkto.panel.health.window_minutes', 60))
                ->map(fn ($health): array => $health->toArray())
                ->values(),
        ];

        if ($request->expectsJson()) {
            return response()->json([
                'latest_messages' => $presenter->messages($data['latest_messages']),
                'connections_health' => $data['connections_health'],
            ]);
        }

        return $this->renderView('talkto::panel.index', $data);
    }

    private function renderView(string $view, array $data): View
    {
        abort_unless(view()->exists($view), 500);

        return view($view, $data);
    }
}
