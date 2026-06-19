@php
    $status = (string) $status;
    $tone = match ($status) {
        'healthy' => 'green',
        'degraded' => 'yellow',
        'failing', 'misconfigured' => 'red',
        default => 'slate',
    };
@endphp
@include('talkto::panel.partials.badge', ['tone' => $tone, 'label' => $status !== '' ? $status : 'unknown'])
