@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', __('talkto::panel.callbacks.title').' '.$message->message_id)

@section('content')
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
    $status = $callback_status;
    $none = __('talkto::panel.callbacks.not_available');
    $display = function (mixed $value) use ($none): string {
        if (is_bool($value)) {
            return $value ? __('talkto::panel.common.yes') : __('talkto::panel.common.no');
        }

        if ($value === null || $value === '') {
            return $none;
        }

        return (string) $value;
    };
@endphp

<div class="space-y-8">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-slate-950">{{ __('talkto::panel.callbacks.title') }}</h2>
            <p class="mt-1 font-mono text-sm text-slate-600">{{ $message->message_id }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.show', ['message' => $message->message_id]), 'label' => __('talkto::panel.callbacks.back_to_message')])
            @if (is_array($status['callback_message'] ?? null) && ! empty($status['callback_message']['message_id']))
                @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.show', ['message' => $status['callback_message']['message_id']]), 'label' => __('talkto::panel.callbacks.open_callback_message')])
            @endif
            @if (is_array($status['parent_message'] ?? null) && ! empty($status['parent_message']['message_id']))
                @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.show', ['message' => $status['parent_message']['message_id']]), 'label' => __('talkto::panel.callbacks.open_parent_message')])
            @endif
        </div>
    </div>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h3 class="text-base font-semibold text-slate-950">{{ $status['label'] ?? __('talkto::panel.callbacks.title') }}</h3>
                <p class="mt-1 text-sm text-slate-600">{{ $status['summary'] ?? $none }}</p>
            </div>
            @include('talkto::panel.partials.badge', ['tone' => ($status['applicable'] ?? false) ? 'blue' : 'slate', 'label' => $display($status['state'] ?? null)])
        </div>
        <dl class="mt-5 grid gap-4 md:grid-cols-4">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.callbacks.context') }}</dt>
                <dd class="mt-1 break-words text-sm text-slate-950">{{ $display($status['context'] ?? null) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.callbacks.state') }}</dt>
                <dd class="mt-1 break-words text-sm text-slate-950">{{ $display($status['state'] ?? null) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.callbacks.label') }}</dt>
                <dd class="mt-1 break-words text-sm text-slate-950">{{ $display($status['label'] ?? null) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('talkto::panel.callbacks.applicable') }}</dt>
                <dd class="mt-1 break-words text-sm text-slate-950">{{ $display($status['applicable'] ?? false) }}</dd>
            </div>
        </dl>
    </section>

    @foreach ([
        'message' => __('talkto::panel.callbacks.message'),
        'callback_message' => __('talkto::panel.callbacks.callback_message'),
        'parent_message' => __('talkto::panel.callbacks.parent_message'),
    ] as $key => $heading)
        <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-base font-semibold text-slate-950">{{ $heading }}</h3>
            @if (is_array($status[$key] ?? null))
                <dl class="mt-5 grid gap-4 md:grid-cols-3">
                    @foreach ($status[$key] as $field => $value)
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ str_replace('_', ' ', (string) $field) }}</dt>
                            <dd class="mt-1 break-words text-sm text-slate-950">{{ $display($value) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="mt-3 rounded-md bg-slate-100 p-3 text-sm text-slate-600">{{ $none }}</p>
            @endif
        </section>
    @endforeach

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-950">{{ __('talkto::panel.callbacks.attempts') }}</h3>
        <dl class="mt-5 grid gap-4 md:grid-cols-4">
            @foreach (($status['attempts'] ?? []) as $field => $value)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ str_replace('_', ' ', (string) $field) }}</dt>
                    <dd class="mt-1 break-words text-sm text-slate-950">{{ $display($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-5 py-4">
            <h3 class="text-base font-semibold text-slate-950">{{ __('talkto::panel.callbacks.events') }}</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-3">{{ __('talkto::panel.messages.table.type') }}</th>
                        <th class="px-5 py-3">{{ __('talkto::panel.messages.table.status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach (($status['events'] ?? []) as $eventType => $seen)
                        <tr>
                            <td class="px-5 py-3">{{ $eventType }}</td>
                            <td class="px-5 py-3">{{ $display($seen) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <h3 class="text-base font-semibold text-slate-950">{{ __('talkto::panel.callbacks.dead_letter') }}</h3>
        <dl class="mt-5 grid gap-4 md:grid-cols-4">
            @foreach (($status['dead_letter'] ?? []) as $field => $value)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ str_replace('_', ' ', (string) $field) }}</dt>
                    <dd class="mt-1 break-words text-sm text-slate-950">{{ $display($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <p class="text-sm text-slate-500">{{ __('talkto::panel.callbacks.read_only_note') }}</p>
</div>
@endsection
