{{-- Видит ли менеджер сделки контакт для получения (15.11.2026, владелец: «админы постоянно спрашивают, как менеджеру эти контакты
     показать»; 25.11.2026 по BMW X5 915891/2026: «менеджер тупо не видит контакты, не понимает, откуда вывозить, кому звонить»).
     С 25.11.2026 — окном «Что видит менеджер» (`x-deal.manager-view`, `ManagerView`): контакт — живой, из карточки; не показан —
     строка дела «Показать контакты менеджеру» (окно с контактом, отмеченным к показу; пуст — карандаш «Контакт»), показан —
     «Менеджер видит контакт» (кого именно). `as`: `block` — под контактом в «Вывозе» (`x-deal.handover`), `task` — строкой дела в шаге
     (`x-route.who`, вместо «Ждём менеджера», когда ход наш — `ContactDue`). `sub` — вторая строка дела. `?contact=1` (ссылка дела из
     списков) открывает окно сразу. Только тем, кто ведёт сделки. --}}
@props(['deal', 'as' => 'block', 'sub' => null, 'danger' => false])
@php
    use App\Offers\ContactDue;
    use App\Offers\ManagerView;
    $live = $deal->buyer_id && $deal->isActive() && ! $deal->isGarage() && auth()->user()?->canEditDeals();
    $shownAt = $live ? ContactDue::shownAt($deal) : null;
    $open = $live && ! $deal->offer->pickedUp();
    $seen = $shownAt ? collect(ManagerView::contacts($deal))->map(fn ($c) => implode(', ', array_filter([$c['name'], $c['phone']])))->first() : null;
    $id = 'manager-view-contact-'.$as.'-'.$deal->id;
@endphp
@if ($live && (! $shownAt || $open || $as === 'block'))
    @if ($shownAt && ! $open)
        <p {{ $attributes->class('text-sm text-ink-muted') }}>Менеджер видел контакт с <span class="nums">{{ \App\Support\Ago::day($shownAt) }}</span></p>
    @else
        <div {{ $attributes->class($as === 'task' ? '' : 'contents') }}>
            <x-deal.manager-view :deal="$deal" :id="$id" :focus="$shownAt ? null : ManagerView::CONTACT" :open="$as === 'task' && ! $shownAt && request()->boolean('contact')">
                @if ($shownAt)
                    <x-route.task-row icon="user">
                        Менеджер видит контакт
                        <x-slot:sub>{{ $seen ?: 'с '.\App\Support\Ago::day($shownAt) }}</x-slot:sub>
                    </x-route.task-row>
                @else
                    <x-route.task-row icon="user" urgent :danger="$danger">
                        Показать контакты менеджеру
                        @if ($sub)
                            <x-slot:sub>{!! $sub !!}</x-slot:sub>
                        @endif
                    </x-route.task-row>
                @endif
            </x-deal.manager-view>
        </div>
    @endif
@endif
