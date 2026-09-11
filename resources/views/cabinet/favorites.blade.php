<x-ui.shell title="Избранное" :wide="true">
    @if ($offers->isEmpty())<p class="text-ink-muted">Отмечайте машины сердцем — они соберутся здесь.</p>@else
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">@foreach ($offers as $offer)<x-offer.tile :offer="$offer"/>@endforeach</div>
        <div class="mt-4">{{ $offers->links() }}</div>
    @endif
</x-ui.shell>
