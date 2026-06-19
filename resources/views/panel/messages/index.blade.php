@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', 'Talkto Messages')

@section('content')
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
@endphp

<div class="space-y-8">
    <div>
        <h2 class="text-2xl font-semibold text-slate-950">Latest Talkto Messages</h2>
        <p class="mt-1 text-sm text-slate-600">Filter local incoming and outgoing message records.</p>
    </div>

    <form method="GET" action="{{ route($routePrefix.'messages.index') }}" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ([
                'direction' => 'Direction',
                'status' => 'Status',
                'service' => 'Service',
                'command' => 'Command',
                'messageId' => 'Message ID',
                'correlationId' => 'Correlation ID',
                'businessKey' => 'Business key',
                'idempotencyKey' => 'Idempotency key',
                'createdFrom' => 'Created from',
                'createdTo' => 'Created to',
            ] as $name => $label)
                <label class="block text-sm">
                    <span class="font-medium text-slate-700">{{ $label }}</span>
                    <input name="{{ $name }}" value="{{ request($name) }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
                </label>
            @endforeach
        </div>
        <div class="mt-4 flex gap-2">
            <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800" type="submit">Apply filters</button>
            @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.index'), 'label' => 'Clear'])
        </div>
    </form>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        @if ($messages->isEmpty())
            <div class="p-5">
                @include('talkto::panel.partials.empty-state', ['title' => 'No messages found', 'message' => 'Try changing the filters or wait for Talkto traffic.'])
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
                            <th class="px-5 py-3">Message ID</th>
                            <th class="px-5 py-3">Business key</th>
                            <th class="px-5 py-3">Retry count</th>
                            <th class="px-5 py-3">Created at</th>
                            <th class="px-5 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($messages as $message)
                            <tr>
                                <td class="px-5 py-3">{{ $message->direction }}</td>
                                <td class="px-5 py-3">@include('talkto::panel.partials.message-status-badge', ['status' => $message->overall_status])</td>
                                <td class="px-5 py-3">{{ $message->direction === 'outgoing' ? $message->target_service : $message->source_service }}</td>
                                <td class="px-5 py-3">{{ $message->command }}</td>
                                <td class="px-5 py-3 font-mono text-xs">{{ $message->message_id }}</td>
                                <td class="px-5 py-3">{{ $message->business_key ?? 'none' }}</td>
                                <td class="px-5 py-3">{{ $message->retry_count }}</td>
                                <td class="px-5 py-3">{{ $message->created_at?->toDateTimeString() }}</td>
                                <td class="px-5 py-3">
                                    <a class="font-medium text-sky-700 hover:text-sky-900" href="{{ route($routePrefix.'messages.show', ['message' => $message->message_id]) }}">View</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-5 pb-5">
                @include('talkto::panel.partials.pagination', ['paginator' => $messages])
            </div>
        @endif
    </section>
</div>
@endsection
