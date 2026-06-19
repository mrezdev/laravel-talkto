<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;

class TalktoPanelConnectionsController
{
    public function index(
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelConnectionRegistry $connections,
        TalktoPanelConnectionHealthChecker $healthChecker,
    ): JsonResponse|View {
        $authorizer->authorize();
        $windowMinutes = (int) config('talkto.panel.health.window_minutes', 60);

        $data = [
            'outgoing' => $connections->outgoing()
                ->map(fn ($connection): array => $connection->toArray())
                ->values(),
            'incoming' => $connections->incoming()
                ->map(fn ($connection): array => $connection->toArray())
                ->values(),
            'health' => $healthChecker->checkAll($windowMinutes)
                ->map(fn ($health): array => $health->toArray())
                ->values(),
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return view('talkto::panel.connections.index', $data);
    }
}
