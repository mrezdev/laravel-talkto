<section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
    @isset($title)
        <h2 class="text-base font-semibold text-slate-950">{{ $title }}</h2>
    @endisset
    @isset($body)
        <div class="@isset($title) mt-4 @endisset">{{ $body }}</div>
    @endisset
</section>
