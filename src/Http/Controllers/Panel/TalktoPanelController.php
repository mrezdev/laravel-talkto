<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelMessageQuery;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;

class TalktoPanelController
{
    public function index(
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelMessageQuery $messages,
        TalktoPanelConnectionHealthChecker $healthChecker,
    ): JsonResponse {
        $authorizer->authorize();

        return response()->json([
            'latest_messages' => $messages->latest(10)->values(),
            'connections_health' => $healthChecker
                ->checkAll((int) config('talkto.panel.health.window_minutes', 60))
                ->map(fn ($health): array => $health->toArray())
                ->values(),
        ]);
    }
}
