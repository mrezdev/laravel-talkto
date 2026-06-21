@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', __('talkto::panel.connections.title'))

@section('content')
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
    $activeHealthByConnection = collect($active_health ?? [])->keyBy(fn ($result) => ($result['direction'] ?? '').'|'.($result['service'] ?? ''));
    $activeHealthEnabled = (bool) ($active_health_enabled ?? false);
@endphp

<div class="space-y-8">
    <div>
        <h2 class="text-2xl font-semibold text-slate-950">{{ __('talkto::panel.connections.heading') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('talkto::panel.connections.description') }}</p>
    </div>

    @foreach ([__('talkto::panel.connections.outgoing_heading') => $outgoing, __('talkto::panel.connections.incoming_heading') => $incoming] as $heading => $connections)
        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="text-base font-semibold text-slate-950">{{ $heading }}</h3>
            </div>
            @if ($connections->isEmpty())
                <div class="p-5">@include('talkto::panel.partials.empty-state', ['title' => __('talkto::panel.connections.empty_title'), 'message' => __('talkto::panel.connections.empty_message')])</div>
            @else
                <div class="grid gap-4 p-5 lg:grid-cols-2">
                    @foreach ($connections as $connection)
                        @php
                            $active = $activeHealthByConnection->get(($connection['direction'] ?? '').'|'.($connection['service'] ?? ''));
                        @endphp
                        <div class="rounded-lg border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-slate-950">{{ $connection['service'] }}</p>
                                    <p class="text-sm text-slate-500">{{ __('talkto::panel.messages.directions.'.$connection['direction']) }}</p>
                                </div>
                                @include('talkto::panel.partials.badge', ['tone' => $connection['configured'] ? 'green' : 'red', 'label' => $connection['configured'] ? __('talkto::panel.common.configured') : __('talkto::panel.common.needs_review')])
                            </div>
                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-slate-500">{{ __('talkto::panel.connections.url_configured') }}</dt>
                                    <dd class="font-medium text-slate-950">{{ $connection['url_configured'] ? __('talkto::panel.common.yes') : __('talkto::panel.common.no') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">{{ __('talkto::panel.connections.secret_configured') }}</dt>
                                    <dd class="font-medium text-slate-950">{{ $connection['secret_configured'] ? __('talkto::panel.common.yes') : __('talkto::panel.common.no') }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-500">{{ __('talkto::panel.connections.endpoint') }}</dt>
                                    <dd class="break-words font-medium text-slate-950">{{ $connection['endpoint'] ?? __('talkto::panel.common.none') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">{{ __('talkto::panel.connections.active_health') }}</dt>
                                    <dd class="mt-1">
                                        @include('talkto::panel.partials.active-health-badge', [
                                            'status' => $active['status'] ?? 'unknown',
                                            'enabled' => $active['enabled'] ?? false,
                                            'configured' => $connection['active_health_configured'] ?? false,
                                        ])
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">{{ __('talkto::panel.connections.health_method') }}</dt>
                                    <dd class="font-medium text-slate-950">{{ $connection['active_health_method'] ?? __('talkto::panel.common.none') }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-500">{{ __('talkto::panel.connections.health_url') }}</dt>
                                    <dd class="break-words font-medium text-slate-950">{{ $connection['active_health_url'] ?? __('talkto::panel.common.none') }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-500">{{ __('talkto::panel.connections.commands') }}</dt>
                                    <dd class="mt-1 text-slate-950">
                                        @if (empty($connection['commands']))
                                            {{ __('talkto::panel.common.none') }}
                                        @else
                                            <ul class="list-inside list-disc">
                                                @foreach ($connection['commands'] as $command)
                                                    <li>{{ $command }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </dd>
                                </div>
                            </dl>
                            @if (! empty($connection['warnings']))
                                <div class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                                    <p class="font-medium">{{ __('talkto::panel.connections.warnings') }}</p>
                                    <ul class="mt-1 list-inside list-disc">
                                        @foreach ($connection['warnings'] as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                            <div class="mt-4 rounded-md bg-slate-50 p-3 text-sm text-slate-700">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="font-medium text-slate-950">{{ __('talkto::panel.connections.active_endpoint_check') }}</p>
                                        <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                            <div><dt class="text-slate-500">{{ __('talkto::panel.connections.checked_at') }}</dt><dd class="font-medium text-slate-950">{{ $active['checked_at'] ?? __('talkto::panel.common.none') }}</dd></div>
                                            <div><dt class="text-slate-500">{{ __('talkto::panel.connections.http_status') }}</dt><dd class="font-medium text-slate-950">{{ $active['http_status'] ?? __('talkto::panel.common.none') }}</dd></div>
                                            <div><dt class="text-slate-500">{{ __('talkto::panel.connections.duration') }}</dt><dd class="font-medium text-slate-950">{{ isset($active['duration_ms']) ? $active['duration_ms'].' ms' : __('talkto::panel.common.none') }}</dd></div>
                                        </dl>
                                    </div>
                                    @if ($activeHealthEnabled && ($connection['active_health_configured'] ?? false))
                                        <form method="POST" action="{{ route($routePrefix.'connections.check', ['direction' => $connection['direction'], 'service' => $connection['service']]) }}">
                                            @csrf
                                            <button type="submit" class="rounded-md bg-slate-950 px-3 py-2 text-sm font-medium text-white hover:bg-slate-800">{{ __('talkto::panel.common.check_now') }}</button>
                                        </form>
                                    @endif
                                </div>
                                @if (! empty($active['warnings']))
                                    <div class="mt-3 rounded-md bg-amber-50 p-3 text-amber-800">
                                        <p class="font-medium">{{ __('talkto::panel.connections.active_warnings') }}</p>
                                        <ul class="mt-1 list-inside list-disc">
                                            @foreach ($active['warnings'] as $warning)
                                                <li>{{ $warning }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">{{ __('talkto::panel.connections.health_heading') }}</h3>
        </div>
        <div class="grid gap-4 p-5 lg:grid-cols-2">
            @forelse ($health as $result)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-950">{{ $result['connection']['service'] ?? __('talkto::panel.common.unknown') }}</p>
                            <p class="text-sm text-slate-500">{{ isset($result['connection']['direction']) ? __('talkto::panel.messages.directions.'.$result['connection']['direction']) : __('talkto::panel.common.unknown') }}</p>
                        </div>
                        @include('talkto::panel.partials.connection-health-badge', ['status' => $result['status'] ?? 'unknown'])
                    </div>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">{{ __('talkto::panel.connections.last_message_at') }}</dt><dd class="font-medium text-slate-950">{{ $result['last_message_at'] ?? __('talkto::panel.common.none') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('talkto::panel.connections.last_success_at') }}</dt><dd class="font-medium text-slate-950">{{ $result['last_success_at'] ?? __('talkto::panel.common.none') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('talkto::panel.connections.last_failure_at') }}</dt><dd class="font-medium text-slate-950">{{ $result['last_failure_at'] ?? __('talkto::panel.common.none') }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('talkto::panel.connections.recent_messages') }}</dt><dd class="font-medium text-slate-950">{{ $result['recent_messages'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('talkto::panel.connections.recent_failures') }}</dt><dd class="font-medium text-slate-950">{{ $result['recent_failures'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('talkto::panel.connections.retry_backlog') }}</dt><dd class="font-medium text-slate-950">{{ $result['retry_backlog'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-500">{{ __('talkto::panel.connections.dead_letters') }}</dt><dd class="font-medium text-slate-950">{{ $result['dead_letters'] ?? 0 }}</dd></div>
                    </dl>
                    @if (! empty($result['warnings']))
                        <div class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                            <p class="font-medium">{{ __('talkto::panel.connections.warnings') }}</p>
                            <ul class="mt-1 list-inside list-disc">
                                @foreach ($result['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @empty
                @include('talkto::panel.partials.empty-state', ['title' => __('talkto::panel.connections.no_health_title'), 'message' => __('talkto::panel.connections.no_health_message')])
            @endforelse
        </div>
    </section>
</div>
@endsection
