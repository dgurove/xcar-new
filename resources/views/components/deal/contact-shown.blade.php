{{-- Видит ли менеджер сделки контакт владельца (15.11.2026, владелец: «админы постоянно спрашивают, как менеджеру эти контакты
     показать»; 25.11.2026 по BMW X5 915891/2026: «менеджер тупо не видит контакты, не понимает, откуда вывозить, кому звонить»).
     Менеджер видит только показанное (`Offers\Disclosure`, правило 03.11.2026): видит — когда ему показан телефон (`ContactDue::shownAt`).
     Не видит — строка дела «Показать контакты менеджеру» и шторка «Показать менеджеру» (`admin.deals.reply-review-sheet`, `card`):
     телефоны, имя и адрес из карточки и из писем поставщика (`Disclosures::contactLetters` — контакт бывает только строкой письма),
     отмечены сразу; документы машины — по желанию; ниже — «Имя» и «Телефон» вписать руками. Контакта в карточке может не быть
     вовсе. `as`: `block` — строкой под контактом в «Вывозе» (`x-deal.handover`), `task` — строкой дела в текущем шаге (`x-route.path`,
     `x-route.who` вместо «Ждём менеджера», когда ход наш — `ContactDue`). Видит, а машину не забрали — строкой «Менеджер видит контакт»
     (что именно) с той же шторкой: поправить и показать ещё; правка контакта в карточке уходит ему сама (`ResendContact`). `sub` — вторая строка дела.
     `?contact=1` (ссылка дела «Показать» из списков) открывает шторку дела сразу. Только тем, кто ведёт сделки. --}}
@props(['deal', 'as' => 'block', 'sub' => null, 'danger' => false])
@php
    use App\Offers\ContactDue;
    use App\Offers\Disclosures;
    $live = $deal->buyer_id && $deal->isActive() && ! $deal->isGarage() && auth()->user()?->canEditDeals();
    $shownAt = $live ? ContactDue::shownAt($deal) : null;
@endphp
@php
    // Показан, а машину ещё не забрали — строкой со шторкой: поправить, дописать, показать ещё (25.11.2026, владелец: «всё равно должна
    // быть возможность их редактировать или ещё указывать, пока он не нажал, что забрал»). Забрали — строкой текста, как было.
    $open = $live && ! $deal->offer->pickedUp();
@endphp
@if ($live && (! $shownAt || $open || $as === 'block'))
    @if ($shownAt && ! $open)
        <p {{ $attributes->class('text-sm text-ink-muted') }}>Менеджер видел контакт с <span class="nums">{{ \App\Support\Ago::day($shownAt) }}</span></p>
    @else
        {{-- Пункты и шторка — только когда «Показать менеджеру» правда есть: разбор документов машины не бесплатный. --}}
        @php
            $id = 'disclose-contact-'.$as.'-'.$deal->id;
            $letters = Disclosures::contactLetters($deal);
            $proposals = Disclosures::proposals($deal, $letters, contact: true);
            // Ещё не показан — отмечено всё контактное; показан — ничего: шторка для правки и «показать ещё».
            $picked = $shownAt ? [] : array_values(array_filter(array_keys($proposals), fn ($k) => preg_match('/^(name|phone|address):/', $k)));
            $seen = $shownAt ? Disclosures::shown($deal) : collect();
            $seenLine = $shownAt ? implode(', ', array_filter([Disclosures::latest($seen, 'name'), Disclosures::latest($seen, 'phone')])) : null;
        @endphp
        <div class="contents" data-controller="sheet">
            @if ($shownAt)
                {{-- Видит — обычной строкой: что именно видит, нажатие — та же шторка. --}}
                <div {{ $attributes->class($as === 'task' ? '' : 'contents') }}>
                    <x-route.task-row icon="user" aria-controls="{{ $id }}">
                        Менеджер видит контакт
                        <x-slot:sub>{{ $seenLine ?: 'с '.\App\Support\Ago::day($shownAt) }}</x-slot:sub>
                    </x-route.task-row>
                </div>
            @elseif ($as === 'task')
                <x-route.task-row icon="user" urgent :danger="$danger" aria-controls="{{ $id }}" {{ $attributes }}>
                    Показать контакты менеджеру
                    @if ($sub)
                        <x-slot:sub>{!! $sub !!}</x-slot:sub>
                    @endif
                </x-route.task-row>
            @else
                {{-- Делом одной строкой, как в шаге (15.11.2026, владелец: рыжая надпись и большая кнопка — «непонятно, что хотят»). --}}
                <div {{ $attributes }}><x-route.task-row icon="user" urgent aria-controls="{{ $id }}">Показать контакты менеджеру</x-route.task-row></div>
            @endif
            @include('admin.deals.reply-review-sheet', ['id' => $id, 'deal' => $deal, 'letters' => $letters, 'proposals' => $proposals, 'step' => null,
                'picked' => $picked, 'card' => true, 'cardTitle' => $shownAt ? 'Контакты менеджеру' : null, 'open' => $as === 'task' && ! $shownAt && request()->boolean('contact')])
        </div>
    @endif
@endif
