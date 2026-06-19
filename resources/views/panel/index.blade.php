@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', 'Talkto Panel')

@section('content')
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
    $latestCount = $latest_messages->count();
    $healthCount = $connections_health->count();
    $statusCounts = $connections_health->groupBy('status')->map->count();
    $attentionCount = (int) ($statusCounts->get('degraded', 0) + $statusCounts->get('failing', 0) + $statusCounts->get('misconfigured', 0) + $statusCounts->get('unknown', 0));
@endphp

<div class="space-y-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-950">Panel Dashboard</h2>
            <p class="mt-1 text-sm text-slate-600">Latest local Talkto messages and passive connection health.</p>
        </div>
        @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.index'), 'label' => 'View messages'])
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Latest messages shown</p>
            <p class="mt-2 text-3xl font-semibold text-slate-950">{{ $latestCount }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Connection health results</p>
            <p class="mt-2 text-3xl font-semibold text-slate-950">{{ $healthCount }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Healthy</p>
            <p class="mt-2 text-3xl font-semibold text-emerald-700">{{ (int) $statusCounts->get('healthy', 0) }}</p>
        </div>
        <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-slate-500">Needs attention or unknown</p>
            <p class="mt-2 text-3xl font-semibold text-amber-700">{{ $attentionCount }}</p>
        </div>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">Latest Talkto Messages</h3>
            @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.index'), 'label' => 'Open messages'])
        </div>
        @if ($latest_messages->isEmpty())
            <div class="p-5">
                @include('talkto::panel.partials.empty-state', ['title' => 'No messages yet', 'message' => 'Talkto messages will appear here after local traffic is recorded.'])
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Direction</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Service</th>
                            <th class="px-5 py-3">Command</th>
                            <th class="px-5 py-3">Created at</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($latest_messages as $message)
                            <tr>
                                <td class="px-5 py-3">{{ $message->direction }}</td>
                                <td class="px-5 py-3">@include('talkto::panel.partials.message-status-badge', ['status' => $message->overall_status])</td>
                                <td class="px-5 py-3">{{ $message->direction === 'outgoing' ? $message->target_service : $message->source_service }}</td>
                                <td class="px-5 py-3">{{ $message->command }}</td>
                                <td class="px-5 py-3">{{ $message->created_at?->toDateTimeString() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">Connections Health</h3>
            @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'connections.index'), 'label' => 'Open connections'])
        </div>
        <div class="grid gap-4 p-5 md:grid-cols-2">
            @forelse ($connections_health as $health)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-950">{{ $health['connection']['service'] ?? 'unknown' }}</p>
                            <p class="text-xs text-slate-500">{{ $health['connection']['direction'] ?? 'unknown' }}</p>
                        </div>
                        @include('talkto::panel.partials.connection-health-badge', ['status' => $health['status'] ?? 'unknown'])
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-slate-500">Recent messages</dt>
                            <dd class="font-medium text-slate-950">{{ $health['recent_messages'] ?? 0 }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-500">Dead letters</dt>
                            <dd class="font-medium text-slate-950">{{ $health['dead_letters'] ?? 0 }}</dd>
                        </div>
                    </dl>
                </div>
            @empty
                @include('talkto::panel.partials.empty-state', ['title' => 'No connections configured', 'message' => 'Configured incoming and outgoing services will appear here.'])
            @endforelse
        </div>
    </section>
</div>
@endsection
