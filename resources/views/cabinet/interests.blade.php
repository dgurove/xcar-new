<x-ui.cabinet title="Интерес" :trail="[['Главная', '/'], ['Кабинет', '/lk'], ['Интерес']]">
    @if ($interests->isEmpty())
        <x-ui.empty href="/" link="В предложения">Вы ещё ничего не отметили.</x-ui.empty>
    @else
        <div class="space-y-3">
            @foreach ($interests as $interest)
                @php $offer = $interest->offer; @endphp
                <div class="box flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4">
                    <a href="/offers/{{ $offer->number }}" class="min-w-0 sm:flex-1">
                        <div class="hover:text-accent-text">{{ $offer->titleWithYear() }}</div>
                        <div class="nums mt-1 text-sm font-normal text-ink-dim">№ {{ $offer->number }} · {{ $interest->created_at->translatedFormat('j M') }}</div>
                    </a>
                    <x-ui.pill :tone="$interest->state === \App\Offers\InterestState::New ? 'plain' : 'soft'" class="self-start">{{ $interest->state === \App\Offers\InterestState::New ? 'Менеджер свяжется' : $interest->state->label() }}</x-ui.pill>
                </div>
            @endforeach
        </div>
        <div class="mt-8">{{ $interests->links() }}</div>
    @endif
</x-ui.cabinet>
