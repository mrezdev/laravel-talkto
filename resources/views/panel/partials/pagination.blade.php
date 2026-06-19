@if ($paginator->hasPages())
    <div class="mt-6">
        {{ $paginator->withQueryString()->links() }}
    </div>
@endif
