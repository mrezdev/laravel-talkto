<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;

class TalktoPanelConnectionsController
{
    public function index(
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelConnectionRegistry $connections,
        TalktoPanelConnectionHealthChecker $healthChecker,
    ): JsonResponse {
        $authorizer->authorize();
        $windowMinutes = (int) config('talkto.panel.health.window_minutes', 60);

        return response()->json([
            'outgoing' => $connections->outgoing()
                ->map(fn ($connection): array => $connection->toArray())
                ->values(),
            'incoming' => $connections->incoming()
                ->map(fn ($connection): array => $connection->toArray())
                ->values(),
            'health' => $healthChecker->checkAll($windowMinutes)
                ->map(fn ($health): array => $health->toArray())
                ->values(),
        ]);
    }
}
