@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', 'Talkto Connections')

@section('content')
<div class="space-y-8">
    <div>
        <h2 class="text-2xl font-semibold text-slate-950">Connections</h2>
        <p class="mt-1 text-sm text-slate-600">Configured services and passive local health. Unknown means no recent local evidence, not a confirmed connection.</p>
    </div>

    @foreach (['Outgoing connections' => $outgoing, 'Incoming connections' => $incoming] as $heading => $connections)
        <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h3 class="text-base font-semibold text-slate-950">{{ $heading }}</h3>
            </div>
            @if ($connections->isEmpty())
                <div class="p-5">@include('talkto::panel.partials.empty-state', ['title' => 'No connections', 'message' => 'No configured services were found for this direction.'])</div>
            @else
                <div class="grid gap-4 p-5 lg:grid-cols-2">
                    @foreach ($connections as $connection)
                        <div class="rounded-lg border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-slate-950">{{ $connection['service'] }}</p>
                                    <p class="text-sm text-slate-500">{{ $connection['direction'] }}</p>
                                </div>
                                @include('talkto::panel.partials.badge', ['tone' => $connection['configured'] ? 'green' : 'red', 'label' => $connection['configured'] ? 'configured' : 'needs review'])
                            </div>
                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-slate-500">URL configured</dt>
                                    <dd class="font-medium text-slate-950">{{ $connection['url_configured'] ? 'yes' : 'no' }}</dd>
                                </div>
                                <div>
                                    <dt class="text-slate-500">Secret configured</dt>
                                    <dd class="font-medium text-slate-950">{{ $connection['secret_configured'] ? 'yes' : 'no' }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-500">Endpoint</dt>
                                    <dd class="break-words font-medium text-slate-950">{{ $connection['endpoint'] ?? 'none' }}</dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-slate-500">Commands</dt>
                                    <dd class="mt-1 text-slate-950">
                                        @if (empty($connection['commands']))
                                            none
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
                                    <p class="font-medium">Warnings</p>
                                    <ul class="mt-1 list-inside list-disc">
                                        @foreach ($connection['warnings'] as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">Health</h3>
        </div>
        <div class="grid gap-4 p-5 lg:grid-cols-2">
            @forelse ($health as $result)
                <div class="rounded-lg border border-slate-200 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold text-slate-950">{{ $result['connection']['service'] ?? 'unknown' }}</p>
                            <p class="text-sm text-slate-500">{{ $result['connection']['direction'] ?? 'unknown' }}</p>
                        </div>
                        @include('talkto::panel.partials.connection-health-badge', ['status' => $result['status'] ?? 'unknown'])
                    </div>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="text-slate-500">Last message at</dt><dd class="font-medium text-slate-950">{{ $result['last_message_at'] ?? 'none' }}</dd></div>
                        <div><dt class="text-slate-500">Last success at</dt><dd class="font-medium text-slate-950">{{ $result['last_success_at'] ?? 'none' }}</dd></div>
                        <div><dt class="text-slate-500">Last failure at</dt><dd class="font-medium text-slate-950">{{ $result['last_failure_at'] ?? 'none' }}</dd></div>
                        <div><dt class="text-slate-500">Recent messages</dt><dd class="font-medium text-slate-950">{{ $result['recent_messages'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-500">Recent failures</dt><dd class="font-medium text-slate-950">{{ $result['recent_failures'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-500">Retry backlog</dt><dd class="font-medium text-slate-950">{{ $result['retry_backlog'] ?? 0 }}</dd></div>
                        <div><dt class="text-slate-500">Dead letters</dt><dd class="font-medium text-slate-950">{{ $result['dead_letters'] ?? 0 }}</dd></div>
                    </dl>
                    @if (! empty($result['warnings']))
                        <div class="mt-4 rounded-md bg-amber-50 p-3 text-sm text-amber-800">
                            <p class="font-medium">Warnings</p>
                            <ul class="mt-1 list-inside list-disc">
                                @foreach ($result['warnings'] as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>
            @empty
                @include('talkto::panel.partials.empty-state', ['title' => 'No health results', 'message' => 'Health results appear when connections are configured.'])
            @endforelse
        </div>
    </section>
</div>
@endsection
