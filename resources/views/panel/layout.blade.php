<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Talkto Panel')</title>
    @if (config('talkto.panel.views.tailwind_cdn', false))
        <script src="https://cdn.tailwindcss.com"></script>
    @endif
</head>
@php
    $routePrefix = config('talkto.panel.route.name', 'talkto.panel.');
@endphp
<body class="min-h-screen bg-slate-50 text-slate-900">
    <div class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
            <div>
                <p class="text-sm font-medium uppercase tracking-wide text-slate-500">Laravel Talkto</p>
                <h1 class="text-2xl font-semibold text-slate-950">Talkto Panel</h1>
            </div>
            <nav class="flex flex-wrap gap-2 text-sm font-medium">
                <a href="{{ route($routePrefix.'index') }}" class="rounded-md px-3 py-2 text-slate-700 hover:bg-slate-100">Dashboard</a>
                <a href="{{ route($routePrefix.'messages.index') }}" class="rounded-md px-3 py-2 text-slate-700 hover:bg-slate-100">Messages</a>
                <a href="{{ route($routePrefix.'connections.index') }}" class="rounded-md px-3 py-2 text-slate-700 hover:bg-slate-100">Connections</a>
            </nav>
        </div>
    </div>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-7xl px-4 pb-8 text-xs text-slate-500 sm:px-6 lg:px-8">
        Laravel Talkto Panel
    </footer>
</body>
</html>
