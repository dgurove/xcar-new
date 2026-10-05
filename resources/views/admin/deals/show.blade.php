@php
    use App\Offers\DealState;
    use App\Workflow\Asks;
    $position = $offer->position();
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Сделки', '/work/deals']">
    <div class="-mt-3 mb-4 flex flex-wrap items-center gap-1.5">
        @if ($deal->state !== DealState::Active)
            <x-ui.pill :tone="$deal->state === DealState::Done ? 'open' : 'danger'">{{ $deal->state->label() }}</x-ui.pill>
            @if ($deal->closed_at)<x-ui.pill tone="plain"><span>{{ $deal->closed_at->translatedFormat('j M Y, H:i') }}</span></x-ui.pill>@endif
        @endif
        {{-- Этап и срок — в карточке «Продажа», в шапке их не повторяем. --}}
        <x-ui.pill tone="plain" href="/offers/{{ $offer->number }}"><x-ui.icon name="car" class="size-4"/> Предложение № {{ $offer->number }}</x-ui.pill>
        @if ($docs)<x-ui.docs-pill :docs="$docs"/>@endif
        @if ($errors->has('exit'))<x-ui.flash tone="danger" class="w-full">{{ $errors->first('exit') }}</x-ui.flash>@endif
    </div>

    <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="contents lg:col-start-1 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            @if ($offer->positions->isNotEmpty())
                @include('admin.offers.route')
            @endif
            <x-deal.handover :deal="$deal" class="order-1"/>
            {{-- Деньги — и у закрытой сделки: вознаграждение и выплата остаются видны. Просьбы и ответы менеджера — в шагах пути. --}}
            <x-deal.money :deal="$deal" class="order-1" id="money"/>
            @if ($deal->hasContract())<x-deal.contract :deal="$deal" class="order-1"/>@endif

            @if ($lastLetter)
                <x-ui.card title="Письма" :count="$letters" class="order-3">
                    <x-mail.last-letter :message="$lastLetter" :count="$letters" :url="'/offers/'.$offer->number.'/letters'" :asks="$asks"/>
                </x-ui.card>
            @endif

            <x-ui.card title="Заметка" class="order-4" id="deal-note">
                <form method="post" action="/work/deals/{{ $deal->id }}/note" class="flex flex-col gap-2" data-controller="save-bar">
                    @csrf
                    <textarea name="notes" class="field-input" placeholder="Что важно помнить по этой сделке">{{ old('notes', $deal->notes) }}</textarea>
                    <x-ui.save-bar page/>
                </form>
            </x-ui.card>
        </div>

        <div class="contents lg:col-start-2 lg:row-start-1 lg:flex lg:flex-col lg:gap-4">
            <x-ui.card class="order-1 overflow-hidden !p-0">
                <a href="/offers/{{ $offer->number }}" class="block aspect-[4/3] bg-surface-3"><x-offer.photo :media="$offer->mainPhoto()" sizes="(min-width: 1024px) 352px, 100vw" class="size-full object-cover"/></a>
                <div class="p-4">
                    <div class="nums text-2xl font-bold leading-none">{{ $deal->isGarage() ? 'В гараж' : \App\Support\Money::rub($deal->amount) }}</div>
                    <div class="mt-2 flex flex-wrap items-center gap-1.5">
                        @if ($offer->asking_price)<span class="tag nums">продажа {{ \App\Support\Money::rub($offer->asking_price) }}</span>@endif
                        <span class="tag nums">{{ $deal->created_at->translatedFormat('j M Y') }}</span>
                    </div>
                    @if ($deal->buyer)<a href="/settings/users/{{ $deal->buyer_id }}" class="mt-3 block font-medium">{{ $deal->buyer->name }}</a>@endif
                    @if ($deal->buyer?->phone)<a href="tel:+{{ $deal->buyer->phone }}" class="text-accent-text">{{ $deal->buyer->phoneFormatted() }}</a>@endif
                    @if ($deal->buyer?->email)<div class="text-sm text-ink-muted">{{ $deal->buyer->email }}</div>@endif
                    @if ($deal->bid?->comment)<div class="mt-2 text-sm">{{ $deal->bid->comment }}</div>@endif
                </div>
            </x-ui.card>
            @if ($deal->buyer?->isManager())<x-chat.staff-line :offer="$offer" :user="$deal->buyer" class="order-1"/>@endif

            <x-ui.card title="История" class="order-5">
                <div class="flex flex-col gap-3 text-sm">
                    @forelse ($events->take(40) as $event)
                        {{-- Как история дела ТС: сверху мелко дата, ниже текст во всю ширину, справа кто — в узкой колонке строка не ломается лесенкой. --}}
                        <div>
                            <div class="nums text-xs text-ink-dim">{{ $event->created_at->translatedFormat('j M, H:i') }}</div>
                            <div class="mt-0.5 flex items-start justify-between gap-3">
                                <div class="min-w-0 flex-1 leading-snug">{{ $event->text() }}</div>
                                @if ($event->user)<span class="shrink-0 text-ink-muted">{{ $event->user->shortName() }}</span>@endif
                            </div>
                        </div>
                    @empty
                        <div class="text-ink-muted">Пока ничего</div>
                    @endforelse
                </div>
            </x-ui.card>
        </div>
    </div>
    <x-mail.window :title="$offer->titleWithYear()"/>
</x-ui.shell>
