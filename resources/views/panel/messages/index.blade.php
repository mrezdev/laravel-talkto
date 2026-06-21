@extends(config('talkto.panel.views.layout', 'talkto::panel.layout'))

@section('title', __('talkto::panel.messages.title'))

@section('content')
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
    $currentFilters = $filters ?? [];
    $directionOptions = collect(\Mrezdev\LaravelTalkto\Enums\TalktoMessageDirection::cases())
        ->mapWithKeys(fn ($case) => [$case->value => __('talkto::panel.messages.directions.'.$case->value)])
        ->all();
    $statusOptions = collect(\Mrezdev\LaravelTalkto\Enums\TalktoMessageStatus::cases())
        ->mapWithKeys(fn ($case) => [$case->value => __('talkto::panel.messages.statuses.'.$case->value)])
        ->all();
    $datetimeLocalValue = static function (?string $value): string {
        if ($value === null || trim($value) === '') {
            return '';
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->format('Y-m-d\TH:i');
        } catch (\Throwable) {
            return '';
        }
    };
@endphp

<div class="space-y-8">
    <div>
        <h2 class="text-2xl font-semibold text-slate-950">{{ __('talkto::panel.messages.index_title') }}</h2>
        <p class="mt-1 text-sm text-slate-600">{{ __('talkto::panel.messages.index_description') }}</p>
    </div>

    <form method="GET" action="{{ route($routePrefix.'messages.index') }}" class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 md:grid-cols-3">
            <label class="block text-sm">
                <span class="font-medium text-slate-700">{{ __('talkto::panel.messages.filters.direction') }}</span>
                <select name="direction" class="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
                    <option value="">{{ __('talkto::panel.common.any') }}</option>
                    @foreach ($directionOptions as $value => $label)
                        <option value="{{ $value }}" @selected(($currentFilters['direction'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block text-sm">
                <span class="font-medium text-slate-700">{{ __('talkto::panel.messages.filters.status') }}</span>
                <select name="status" class="mt-1 block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
                    <option value="">{{ __('talkto::panel.common.any') }}</option>
                    @foreach ($statusOptions as $value => $label)
                        <option value="{{ $value }}" @selected(($currentFilters['status'] ?? null) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            @foreach ([
                'service' => __('talkto::panel.messages.filters.service'),
                'command' => __('talkto::panel.messages.filters.command'),
                'messageId' => __('talkto::panel.messages.filters.message_id'),
                'correlationId' => __('talkto::panel.messages.filters.correlation_id'),
                'businessKey' => __('talkto::panel.messages.filters.business_key'),
                'idempotencyKey' => __('talkto::panel.messages.filters.idempotency_key'),
            ] as $name => $label)
                <label class="block text-sm">
                    <span class="font-medium text-slate-700">{{ $label }}</span>
                    <input type="text" name="{{ $name }}" value="{{ $currentFilters[\Illuminate\Support\Str::snake($name)] ?? null }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
                </label>
            @endforeach

            <label class="block text-sm">
                <span class="font-medium text-slate-700">{{ __('talkto::panel.messages.filters.created_from') }}</span>
                <input type="datetime-local" name="createdFrom" value="{{ $datetimeLocalValue($currentFilters['created_from'] ?? null) }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
            </label>

            <label class="block text-sm">
                <span class="font-medium text-slate-700">{{ __('talkto::panel.messages.filters.created_to') }}</span>
                <input type="datetime-local" name="createdTo" value="{{ $datetimeLocalValue($currentFilters['created_to'] ?? null) }}" class="mt-1 block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-sky-500 focus:outline-none focus:ring-1 focus:ring-sky-500">
            </label>
        </div>
        <div class="mt-4 flex gap-2">
            <button class="rounded-md bg-slate-950 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800" type="submit">{{ __('talkto::panel.common.apply_filters') }}</button>
            @include('talkto::panel.partials.button-link', ['href' => route($routePrefix.'messages.index'), 'label' => __('talkto::panel.common.clear')])
        </div>
    </form>

    <section class="rounded-lg border border-slate-200 bg-white shadow-sm">
        @if ($messages->isEmpty())
            <div class="p-5">
                @include('talkto::panel.partials.empty-state', ['title' => __('talkto::panel.messages.empty_title'), 'message' => __('talkto::panel.messages.empty_message')])
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.direction') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.status') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.service') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.command') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.message_id') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.business_key') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.retry_count') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.created_at') }}</th>
                            <th class="px-5 py-3">{{ __('talkto::panel.messages.table.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($messages as $message)
                            <tr>
                                <td class="px-5 py-3">{{ __('talkto::panel.messages.directions.'.$message->direction) }}</td>
                                <td class="px-5 py-3">@include('talkto::panel.partials.message-status-badge', ['status' => $message->overall_status])</td>
                                <td class="px-5 py-3">{{ $message->direction === 'outgoing' ? $message->target_service : $message->source_service }}</td>
                                <td class="px-5 py-3">{{ $message->command }}</td>
                                <td class="px-5 py-3 font-mono text-xs">{{ $message->message_id }}</td>
                                <td class="px-5 py-3">{{ $message->business_key ?? __('talkto::panel.common.none') }}</td>
                                <td class="px-5 py-3">{{ $message->retry_count }}</td>
                                <td class="px-5 py-3">{{ $message->created_at?->toDateTimeString() }}</td>
                                <td class="px-5 py-3">
                                    <a class="font-medium text-sky-700 hover:text-sky-900" href="{{ route($routePrefix.'messages.show', ['message' => $message->message_id]) }}">{{ __('talkto::panel.common.view') }}</a>
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
