@php
    $enabled = (bool) ($enabled ?? true);
    $configured = (bool) ($configured ?? false);
    $status = (string) ($status ?? 'unknown');
    $label = ! $enabled ? 'disabled' : str_replace('_', ' ', ($status !== '' ? $status : 'unknown'));
    $tone = match ($status) {
        'healthy' => 'green',
        'failing', 'misconfigured' => 'red',
        'not_configured', 'not_applicable' => 'yellow',
        default => $enabled && $configured ? 'slate' : 'yellow',
    };
@endphp
@include('talkto::panel.partials.badge', ['tone' => $tone, 'label' => $label])
