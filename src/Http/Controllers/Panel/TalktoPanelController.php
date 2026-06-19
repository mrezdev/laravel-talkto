<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;

class TalktoPanelController
{
    public function index(
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
        TalktoPanelConnectionHealthChecker $healthChecker,
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
            return response()->json($data);
        }

        return view('talkto::panel.index', $data);
    }
}
