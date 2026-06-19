@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', 'Talkto Message '.$message->message_id)

@section('content')
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
    $showPayload = (bool) config('talkto.panel.messages.show_payload', false);
    $showResponse = (bool) config('talkto.panel.messages.show_response', false);
@endphp

<div class="space-y-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-950">Message Detail</h2>
            <p class="mt-1 font-mono text-sm text-slate-600">{{ $message->message_id }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.trace', ['message' => $message->message_id]), 'label' => 'Trace'])
            @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.index'), 'label' => 'Back to messages'])
        </div>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-950">Actions</h3>
        <div class="mt-4 flex flex-wrap gap-3">
            @if (config('talkto.panel.actions.retry_enabled', true))
                <form method="POST" action="{{ route($routePrefix.'messages.retry', ['message' => $message->message_id]) }}">
                    @csrf
                    <button type="submit" class="rounded-md bg-slate-950 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">Retry now</button>
                </form>
            @endif
            <a class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50" href="{{ route($routePrefix.'messages.trace', ['message' => $message->message_id]) }}">View trace</a>
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center justify-between gap-4">
            <h3 class="text-base font-semibold text-slate-950">Summary</h3>
            @include('talkto::panel.partials.message-status-badge', ['status' => $message->overall_status])
        </div>
        <dl class="mt-5 grid gap-4 md:grid-cols-3">
            @foreach ([
                'id' => $message->id,
                'message_id' => $message->message_id,
                'direction' => $message->direction,
                'source_service' => $message->source_service,
                'target_service' => $message->target_service,
                'command' => $message->command,
                'overall_status' => $message->overall_status,
                'correlation_id' => $message->correlation_id,
                'business_key' => $message->business_key,
                'idempotency_key' => $message->idempotency_key,
                'retry_count' => $message->retry_count,
                'next_retry_at' => $message->next_retry_at?->toDateTimeString(),
                'created_at' => $message->created_at?->toDateTimeString(),
                'updated_at' => $message->updated_at?->toDateTimeString(),
            ] as $label => $value)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ str_replace('_', ' ', $label) }}</dt>
                    <dd class="mt-1 break-words text-sm text-slate-950">{{ $value ?? 'none' }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($message->last_error)
            <div class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4">
                <p class="text-sm font-semibold text-rose-800">Last error</p>
                <p class="mt-1 whitespace-pre-wrap text-sm text-rose-800">{{ $message->last_error }}</p>
            </div>
        @endif
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-950">Payload</h3>
        @if ($showPayload)
            <pre class="mt-4 overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($message_data['payload'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @else
            <p class="mt-3 rounded-md bg-slate-100 p-3 text-sm text-slate-600">Payload is hidden by panel config.</p>
        @endif
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-950">Response</h3>
        @if ($showResponse)
            <pre class="mt-4 overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-100">{{ json_encode($message_data['last_response'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
        @else
            <p class="mt-3 rounded-md bg-slate-100 p-3 text-sm text-slate-600">Response is hidden by panel config.</p>
        @endif
    </section>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">Attempts</h3>
        </div>
        @if ($attempts->isEmpty())
            <div class="p-5">@include('talkto::panel.partials.empty-state', ['title' => 'No attempts', 'message' => 'No attempt rows are linked to this message.'])</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Stage</th>
                            <th class="px-5 py-3">Attempt</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">HTTP</th>
                            <th class="px-5 py-3">Error</th>
                            <th class="px-5 py-3">Created at</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($attempts as $attempt)
                            <tr>
                                <td class="px-5 py-3">{{ $attempt->stage }}</td>
                                <td class="px-5 py-3">{{ $attempt->attempt_no }}</td>
                                <td class="px-5 py-3">{{ $attempt->status }}</td>
                                <td class="px-5 py-3">{{ $attempt->http_status ?? 'none' }}</td>
                                <td class="px-5 py-3">{{ $attempt->error_message ?? 'none' }}</td>
                                <td class="px-5 py-3">{{ $attempt->created_at?->toDateTimeString() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">Events</h3>
        </div>
        @if ($events->isEmpty())
            <div class="p-5">@include('talkto::panel.partials.empty-state', ['title' => 'No events', 'message' => 'No event rows are linked to this message.'])</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">Type</th>
                            <th class="px-5 py-3">Old status</th>
                            <th class="px-5 py-3">New status</th>
                            <th class="px-5 py-3">Created at</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($events as $event)
                            <tr>
                                <td class="px-5 py-3">{{ $event->event_type }}</td>
                                <td class="px-5 py-3">{{ $event->old_status ?? 'none' }}</td>
                                <td class="px-5 py-3">{{ $event->new_status ?? 'none' }}</td>
                                <td class="px-5 py-3">{{ $event->created_at?->toDateTimeString() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($dead_letter)
        <section class="rounded-lg border border-rose-200 bg-rose-50 p-5 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h3 class="text-base font-semibold text-rose-950">Dead letter</h3>
                @if (config('talkto.panel.actions.dead_letter_reprocess_enabled', true))
                    <form method="POST" action="{{ route($routePrefix.'dead-letters.reprocess', ['deadLetter' => $dead_letter->id]) }}">
                        @csrf
                        <button type="submit" class="rounded-md bg-rose-700 px-4 py-2 text-sm font-medium text-white hover:bg-rose-800">Reprocess dead letter</button>
                    </form>
                @endif
            </div>
            <dl class="mt-4 grid gap-4 md:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-rose-700">Status</dt>
                    <dd class="mt-1 text-sm text-rose-950">{{ $dead_letter->status }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-rose-700">Failed status</dt>
                    <dd class="mt-1 text-sm text-rose-950">{{ $dead_letter->failed_status ?? 'none' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-rose-700">Reprocess count</dt>
                    <dd class="mt-1 text-sm text-rose-950">{{ $dead_letter->reprocess_count }}</dd>
                </div>
            </dl>
            @if ($dead_letter->failure_reason)
                <p class="mt-4 whitespace-pre-wrap text-sm text-rose-900">{{ $dead_letter->failure_reason }}</p>
            @endif
        </section>
    @endif

    <p class="text-sm text-slate-500">Only safe retry, reprocess, and trace actions are available from this panel.</p>
</div>
@endsection
