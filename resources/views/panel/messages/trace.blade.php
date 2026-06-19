@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', 'Talkto Trace '.$message->message_id)

@section('content')
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
    $payloadAllowed = (bool) config('talkto.panel.messages.show_payload', false);
@endphp

<div class="space-y-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-950">Message Trace</h2>
            <p class="mt-1 font-mono text-sm text-slate-600">{{ $message->message_id }}</p>
        </div>
        @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.show', ['message' => $message->message_id]), 'label' => 'Back to message'])
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-950">Trace Summary</h3>
        <dl class="mt-5 grid gap-4 md:grid-cols-4">
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Found</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['found'] ? 'yes' : 'no' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Correlation ID</dt><dd class="mt-1 break-words text-sm text-slate-950">{{ $trace_data['correlation_id'] ?? 'none' }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Related messages</dt><dd class="mt-1 text-sm text-slate-950">{{ count($trace_data['related_messages']) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Attempts</dt><dd class="mt-1 text-sm text-slate-950">{{ count($trace_data['attempts']) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Events</dt><dd class="mt-1 text-sm text-slate-950">{{ count($trace_data['events']) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Dead letters</dt><dd class="mt-1 text-sm text-slate-950">{{ count($trace_data['dead_letters']) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Limit</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['limit'] }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Truncated</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['truncated'] ? 'yes' : 'no' }}</dd></div>
        </dl>
        @if (! $payloadAllowed || ! $payload_requested)
            <p class="mt-4 rounded-md bg-slate-100 p-3 text-sm text-slate-600">Payload is hidden unless panel config allows payload display and the trace request includes payload=1.</p>
        @endif
    </section>

    @if (is_array($trace_data['anchor_message']))
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-semibold text-slate-950">Anchor Message</h3>
            <dl class="mt-5 grid gap-4 md:grid-cols-3">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Message ID</dt><dd class="mt-1 break-words text-sm text-slate-950">{{ $trace_data['anchor_message']['message_id'] ?? 'none' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Direction</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['anchor_message']['direction'] ?? 'none' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['anchor_message']['overall_status'] ?? 'none' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Command</dt><dd class="mt-1 break-words text-sm text-slate-950">{{ $trace_data['anchor_message']['command'] ?? 'none' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Source</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['anchor_message']['source_service'] ?? 'none' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Target</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['anchor_message']['target_service'] ?? 'none' }}</dd></div>
            </dl>
            @if ($payloadAllowed && $payload_requested)
                <pre class="mt-4 overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($trace_data['anchor_message']['payload'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </section>
    @endif

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">Timeline</h3>
        </div>
        @if ($trace_data['timeline'] === [])
            <div class="p-5">@include('talkto::panel.partials.empty-state', ['title' => 'No trace timeline', 'message' => 'No related timeline entries were found.'])</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">At</th>
                            <th class="px-5 py-3">Type</th>
                            <th class="px-5 py-3">Message ID</th>
                            <th class="px-5 py-3">Status/Event</th>
                            <th class="px-5 py-3">Summary</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($trace_data['timeline'] as $entry)
                            <tr>
                                <td class="px-5 py-3">{{ $entry['at'] ?? 'none' }}</td>
                                <td class="px-5 py-3">{{ $entry['type'] ?? 'none' }}</td>
                                <td class="px-5 py-3 font-mono text-xs">{{ $entry['message_id'] ?? 'none' }}</td>
                                <td class="px-5 py-3">{{ $entry['status'] ?? $entry['event_type'] ?? 'none' }}</td>
                                <td class="px-5 py-3">{{ $entry['summary'] ?? 'none' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($trace_data['warnings'] !== [])
        <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 shadow-sm">
            <h3 class="text-base font-semibold text-amber-950">Warnings</h3>
            <ul class="mt-3 list-inside list-disc text-sm text-amber-900">
                @foreach ($trace_data['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
@endsection
