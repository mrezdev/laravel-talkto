@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', __('talkto::panel.trace.title', ['message' => $message->message_id]))

@section('content')
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
    $payloadAllowed = (bool) config('talkto.panel.messages.show_payload', false);
@endphp

<div class="space-y-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-950">{{ __('talkto::panel.messages.trace_title') }}</h2>
            <p class="mt-1 font-mono text-sm text-slate-600">{{ $message->message_id }}</p>
        </div>
        @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.show', ['message' => $message->message_id]), 'label' => __('talkto::panel.common.back_to_message')])
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-950">{{ __('talkto::panel.trace.summary_title') }}</h3>
        <dl class="mt-5 grid gap-4 md:grid-cols-4">
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.found') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['found'] ? __('talkto::panel.common.yes') : __('talkto::panel.common.no') }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.correlation_id') }}</dt><dd class="mt-1 break-words text-sm text-slate-950">{{ $trace_data['correlation_id'] ?? __('talkto::panel.common.none') }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.related_messages') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ count($trace_data['related_messages']) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.attempts') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ count($trace_data['attempts']) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.events') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ count($trace_data['events']) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.dead_letters') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ count($trace_data['dead_letters']) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.limit') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['limit'] }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.truncated') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['truncated'] ? __('talkto::panel.common.yes') : __('talkto::panel.common.no') }}</dd></div>
        </dl>
        @if (! $payloadAllowed || ! $payload_requested)
            <p class="mt-4 rounded-md bg-slate-100 p-3 text-sm text-slate-600">{{ __('talkto::panel.trace.payload_hidden') }}</p>
        @endif
    </section>

    @if (is_array($trace_data['anchor_message']))
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-semibold text-slate-950">{{ __('talkto::panel.trace.anchor_message') }}</h3>
            <dl class="mt-5 grid gap-4 md:grid-cols-3">
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.messages.table.message_id') }}</dt><dd class="mt-1 break-words text-sm text-slate-950">{{ $trace_data['anchor_message']['message_id'] ?? __('talkto::panel.common.none') }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.messages.table.direction') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ isset($trace_data['anchor_message']['direction']) ? __('talkto::panel.messages.directions.'.$trace_data['anchor_message']['direction']) : __('talkto::panel.common.none') }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.messages.table.status') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ isset($trace_data['anchor_message']['overall_status']) ? __('talkto::panel.messages.statuses.'.$trace_data['anchor_message']['overall_status']) : __('talkto::panel.common.none') }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.messages.table.command') }}</dt><dd class="mt-1 break-words text-sm text-slate-950">{{ $trace_data['anchor_message']['command'] ?? __('talkto::panel.common.none') }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.source') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['anchor_message']['source_service'] ?? __('talkto::panel.common.none') }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.trace.target') }}</dt><dd class="mt-1 text-sm text-slate-950">{{ $trace_data['anchor_message']['target_service'] ?? __('talkto::panel.common.none') }}</dd></div>
            </dl>
            @if ($payloadAllowed && $payload_requested)
                <pre class="mt-4 overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($trace_data['anchor_message']['payload'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </section>
    @endif

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">{{ __('talkto::panel.trace.timeline') }}</h3>
        </div>
        @if ($trace_data['timeline'] === [])
            <div class="p-5">@include('talkto::panel.partials.empty-state', ['title' => __('talkto::panel.trace.empty_timeline_title'), 'message' => __('talkto::panel.trace.empty_timeline_message')])</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.at') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.type') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.message_id') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.status_event') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.summary') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($trace_data['timeline'] as $entry)
                            <tr>
                                <td class="px-5 py-3">{{ $entry['at'] ?? __('talkto::panel.common.none') }}</td>
                                <td class="px-5 py-3">{{ $entry['type'] ?? __('talkto::panel.common.none') }}</td>
                                <td class="px-5 py-3 font-mono text-xs">{{ $entry['message_id'] ?? __('talkto::panel.common.none') }}</td>
                                <td class="px-5 py-3">{{ $entry['status'] ?? $entry['event_type'] ?? __('talkto::panel.common.none') }}</td>
                                <td class="px-5 py-3">{{ $entry['summary'] ?? __('talkto::panel.common.none') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($trace_data['warnings'] !== [])
        <section class="rounded-lg border border-amber-200 bg-amber-50 p-5 shadow-sm">
            <h3 class="text-base font-semibold text-amber-950">{{ __('talkto::panel.trace.warnings') }}</h3>
            <ul class="mt-3 list-inside list-disc text-sm text-amber-900">
                @foreach ($trace_data['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
@endsection
