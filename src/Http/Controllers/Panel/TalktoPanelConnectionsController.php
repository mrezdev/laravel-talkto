<?php

namespace Mrezdev\LaravelTalkto\Http\Controllers\Panel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelActiveHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionHealthChecker;
use Mrezdev\LaravelTalkto\Services\Panel\TalktoPanelConnectionRegistry;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelAuthorizer;
use Mrezdev\LaravelTalkto\Support\Panel\TalktoPanelConnection;

/**
 * @internal Optional panel controller; use panel routes/views rather than this class.
 */
class TalktoPanelConnectionsController
{
    public function index(
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelConnectionRegistry $connections,
        TalktoPanelConnectionHealthChecker $healthChecker,
        TalktoPanelActiveHealthChecker $activeHealthChecker,
    ): JsonResponse|View {
        $authorizer->authorize();
        $windowMinutes = (int) config('talkto.panel.health.window_minutes', 60);
        $activeHealth = $activeHealthChecker->checkAll();

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
            'active_health' => $activeHealth
                ->map(fn ($health): array => $health->toArray())
                ->values(),
            'active_health_enabled' => $activeHealthChecker->enabled(),
        ];

        if ($request->expectsJson()) {
            return response()->json($data);
        }

        return $this->renderView('talkto::panel.connections.index', $data);
    }

    public function check(
        string $direction,
        string $service,
        Request $request,
        TalktoPanelAuthorizer $authorizer,
        TalktoPanelConnectionRegistry $connections,
        TalktoPanelActiveHealthChecker $activeHealthChecker,
    ): JsonResponse|RedirectResponse {
        $authorizer->authorize();

        abort_unless(in_array($direction, ['incoming', 'outgoing'], true), 404);

        $connection = $this->findConnection($connections, $direction, $service);

        abort_if($connection === null, 404);

        $result = $activeHealthChecker->check($connection, force: true);
        $status = $result->enabled ? 200 : 422;

        if ($request->expectsJson()) {
            return response()->json($result->toArray(), $status);
        }

        $message = $this->flashMessage($result->toArray());

        return redirect()
            ->back()
            ->with($result->status === 'healthy' ? 'talkto_panel_status' : 'talkto_panel_error', $message);
    }

    private function findConnection(TalktoPanelConnectionRegistry $connections, string $direction, string $service): ?TalktoPanelConnection
    {
        return $connections->all()
            ->first(fn (TalktoPanelConnection $connection): bool => $connection->direction === $direction && $connection->service === $service);
    }

    private function renderView(string $view, array $data): View
    {
        abort_unless(view()->exists($view), 500);

        return view($view, $data);
    }

    private function flashMessage(array $result): string
    {
        $status = (string) ($result['status'] ?? 'unknown');
        $service = (string) ($result['service'] ?? 'unknown');

        if ($status === 'healthy') {
            return __('talkto::panel.connections.active_health_healthy', ['service' => $service]);
        }

        if (($result['enabled'] ?? false) === false) {
            return __('talkto::panel.connections.active_health_disabled');
        }

        return __('talkto::panel.connections.active_health_status', [
            'service' => $service,
            'status' => $status,
        ]);
    }
}
