{{-- Карточка строки таблицы машин закупки на сайте (фрейм detail) — цена без страницы:
     лента фото, метки, своя цена (менеджер) или лучшая (сотрудник), факты; менеджеру —
     поле «Предложение» с комментарием, «Предложить / Изменить», «Отозвать цену»,
     выбранная — сообщением; ниже описание. Формы отвечают в карточку (DetailBack),
     строка — свежей из row. --}}
@php
    $user = auth()->user();
    $staff = $user?->isAdmin() ?? false;
    $mine = $staff ? null : $car->offerOf($user);
    $best = $staff ? $car->bestOffer() : null;
    $suffix = $query ? '?'.$query : '';
    $href = "/purchases/{$purchase->number}/{$car->ref}{$suffix}";
@endphp
<x-ui.detail>
    <x-ui.row-card :href="$href" :title="$car->titleWithYear()" :photos="$car->visiblePhotos()" :facts="$car->facts()" :action="false">
        <x-slot:marks>
            <span class="tag nums gap-1">№<x-ui.copy-code :value="$car->ref"/></span>
            <x-ui.vin-code :vin="$car->vin" class="tag"/>
            @if ($car->fssp)<x-ui.state tone="urgent">Ограничения ФССП</x-ui.state>@endif
        </x-slot:marks>
        <x-slot:line>
            @if ($car->settlement?->title() ?? $car->city)<x-ui.place>{{ $car->settlement?->title() ?? $car->city }}</x-ui.place>@endif
            <span>{{ mb_strtolower($car->kind->label()) }}</span>
        </x-slot:line>
        <x-slot:aside>
            @if ($staff && $best)<span class="nums whitespace-nowrap text-lg font-bold">{{ \App\Support\Money::rub($best->amount) }}</span> <span class="text-sm text-ink-dim">{{ $best->user->shortName() }}</span>
            @elseif ($mine)<span class="nums whitespace-nowrap text-lg font-bold text-accent-text">{{ \App\Support\Money::rub($mine->amount) }}</span>
            @elseif (!$staff)<span class="tag tag-amber">Без цены</span>@endif
        </x-slot:aside>
        <x-slot:actions>
            @if (!$staff)
                @if ($mine?->state === \App\Offers\BidState::Accepted)
                    <span class="flash flash-accent w-full text-sm">Ваша цена {{ \App\Support\Money::rub($mine->amount) }} выбрана, с Вами свяжутся</span>
                @elseif ($purchase->acceptsOffers())
                    <x-offer.bid-form :my-bid="$mine" :action="'/purchases/'.$purchase->number.'/'.$car->ref.'/price'.$suffix" :withdraw="$mine ? '/purchases/prices/'.$mine->id.'/withdraw' : null" compact/>
                @else
                    <span class="text-sm text-ink-muted">Приём цен закрыт</span>
                @endif
            @endif
        </x-slot:actions>
        @if ($car->description)<p class="mt-3 whitespace-pre-line text-sm text-ink-muted">{{ \App\Support\VendorNames::strip($car->description) }}</p>@endif
    </x-ui.row-card>
</x-ui.detail>
