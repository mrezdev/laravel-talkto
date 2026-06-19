@php
    $tone = $tone ?? 'slate';
    $classes = [
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'yellow' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'red' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-600/20',
    ][$tone] ?? 'bg-slate-100 text-slate-700 ring-slate-600/20';
@endphp
<span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $classes }}">
    {{ $label ?? '' }}
</span>
