{{-- Окошко строки таблицы машин закупки на сайте (фрейм peek) — цена без страницы:
     лента фото, метки, своя цена (менеджер) или лучшая (сотрудник), факты; менеджеру —
     поле «Предложение» с комментарием, «Предложить / Изменить», «Отозвать цену»,
     выбранная — сообщением; ниже описание. Формы отвечают в окошко (PeekBack),
     строка — свежей из row. --}}
@php
    $user = auth()->user();
    $staff = $user?->isStaff() ?? false;
    $mine = $staff ? null : $car->offerOf($user);
    $best = $staff ? $car->bestOffer() : null;
    $suffix = $query ? '?'.$query : '';
    $href = "/purchases/{$purchase->number}/{$car->ref}{$suffix}";
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$car->titleWithYear()" :photos="$car->visiblePhotos()" :facts="$car->facts()" :action="false">
        <x-slot:marks>
            <span class="tag nums">№ {{ $car->ref }}</span>
            <span class="tag">{{ $car->kind->label() }}</span>
            @if ($car->settlement?->name ?? $car->city)<x-ui.place class="tag">{{ $car->settlement?->name ?? $car->city }}</x-ui.place>@endif
            <x-ui.vin-code :vin="$car->vin" class="tag"/>
            @if ($car->fssp)<span class="tag">Ограничения ФССП</span>@endif
        </x-slot:marks>
        <x-slot:aside>
            @if ($staff && $best)<span class="nums whitespace-nowrap text-[17px] font-bold">{{ \App\Support\Money::rub($best->amount) }}</span> <span class="text-sm text-ink-dim">{{ $best->user->shortName() }}</span>
            @elseif ($mine)<span class="nums whitespace-nowrap text-[17px] font-bold text-accent-text">{{ \App\Support\Money::rub($mine->amount) }}</span>
            @elseif (!$staff)<span class="tag" style="--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d">Без цены</span>@endif
        </x-slot:aside>
        <x-slot:actions>
            @if (!$staff)
                @if ($mine?->state === \App\Purchases\OfferState::Chosen)
                    <span class="flash flash-accent w-full text-sm">Ваша цена {{ \App\Support\Money::rub($mine->amount) }} выбрана — с Вами свяжутся</span>
                @elseif ($purchase->acceptsOffers())
                    <form method="post" action="/purchases/{{ $purchase->number }}/{{ $car->ref }}/price{{ $suffix }}" class="flex w-full flex-col gap-2" data-controller="bid" data-bid-asking-value="0" data-bid-min-value="0">
                        @csrf
                        <div class="flex gap-2">
                            <input type="hidden" name="amount" data-bid-target="amount" value="{{ old('amount', $mine?->amount) }}">
                            <input type="text" inputmode="numeric" required autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1" placeholder="Предложение, ₽" aria-label="Предложение, ₽" data-bid-target="display" data-action="input->bid#input" value="{{ old('amount', $mine?->amount ? \App\Support\Money::nums($mine->amount) : '') }}" data-peek-focus>
                            <button type="submit" class="btn btn-s btn-accent shrink-0" data-bid-target="submit">{{ $mine ? 'Изменить' : 'Предложить' }}</button>
                        </div>
                        @error('amount')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                        <input name="comment" class="field-input field-s text-sm" placeholder="Комментарий" value="{{ old('comment', $mine?->comment) }}">
                    </form>
                    @if ($mine)<form method="post" action="/purchases/prices/{{ $mine->id }}/withdraw" class="contents">@csrf<button type="submit" class="pill pill-plain">Отозвать цену</button></form>@endif
                @else
                    <span class="text-sm text-ink-muted">Приём цен закрыт</span>
                @endif
            @endif
        </x-slot:actions>
        @if ($car->description)<p class="mt-3 whitespace-pre-line text-sm text-ink-muted">{{ $car->description }}</p>@endif
        <x-slot:row><x-purchase.site-table-row :car="$car" :purchase="$purchase" :query="$query"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
