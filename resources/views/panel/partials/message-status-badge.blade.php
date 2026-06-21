@php
    $status = (string) $status;
    $tone = match (true) {
        in_array($status, ['completed', 'succeeded'], true) => 'green',
        in_array($status, ['failed_final', 'failed'], true) => 'red',
        in_array($status, ['failed_retryable', 'processing'], true) => 'yellow',
        default => 'slate',
    };
@endphp
@include('talkto::panel.partials.badge', ['tone' => $tone, 'label' => __('talkto::panel.messages.statuses.'.($status !== '' ? $status : 'unknown'))])
