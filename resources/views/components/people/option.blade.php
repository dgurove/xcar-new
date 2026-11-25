{{-- Человек строкой выбора — одна разметка на всё приложение: `x-people.pick`, «Кто платит» (`x-billing.payer-pick`),
     «Кто заберёт» (`x-deal.receiver-sheet`); тот же кусок отдаёт сервер для только что заведённого (`PeopleController`).
     Аватар, имя, подпись, галка; `action` — что делает выбор (по умолчанию — шторка `x-people.pick`). --}}
@props(['person', 'name', 'checked' => false, 'type' => 'radio', 'action' => 'change->people-pick#choose', 'picker' => true, 'edit' => null, 'editEvent' => null, 'mark' => true])
{{-- picker=false — строка вне шторки `x-people.pick` (плательщик, получатель): без её меток поиска и адреса. --}}
<label @class(['row row-check', 'people-option' => $picker, 'is-off' => $person['disabled'] ?? false]) @if ($picker) data-people-option data-search-text="{{ str_replace('ё', 'е', mb_strtolower($person['name'].' '.($person['sub'] ?? '').' '.($person['group'] ?? ''))) }}" @isset($person['address']) data-address="{{ $person['address'] }}" @endisset @isset($person['holder']) data-holder="{{ $person['holder'] }}" @endisset @endif>
    {{-- `logo` — площадка («Администрация XCAR» в «Написать» чатов): знак приложения, а не буквы. --}}
    @if ($person['logo'] ?? false)<x-chat.avatar :size="36"/>
    @elseif ($person['user'] ?? null)<x-ui.avatar :user="$person['user']" :size="36"/>@else<x-ui.avatar :name="$person['name']" :size="36"/>@endif
    <span class="min-w-0 flex-1">
        <span class="block truncate" data-people-name>{{ $person['name'] }}</span>
        @if ($person['sub'] ?? null)<span class="block truncate text-sm text-ink-muted">{{ $person['sub'] }}</span>@endif
    </span>
    {{-- Карандаш — поправить человека (`People::editUrl`): переход страницей, выбор строки он не трогает. --}}
    {{-- `editEvent` — данные этого человека своей шторкой поверх (покупатель по договору в «Покупателе» сделки, 22.11.2026). --}}
    @if ($editEvent)<button type="button" class="btn btn-ghost btn-s shrink-0 px-2 text-ink-muted" data-controller="emit" data-action="sheet#close emit#send" data-emit-event-param="{{ $editEvent }}" aria-label="Изменить данные" title="Изменить данные"><x-ui.icon name="edit" class="size-5"/></button>
    @elseif ($edit)<a href="{{ $edit }}" class="btn btn-ghost btn-s shrink-0 px-2 text-ink-muted" data-turbo-frame="_top" aria-label="Изменить данные" title="Изменить данные"><x-ui.icon name="edit" class="size-5"/></a>@endif
    {{-- mark=false — выбор сразу действие («Написать» чатов, 23.11.2026, владелец: «какую нагрузку несут кружочки»): радио без кружка. --}}
    <span @class(['check' => $mark, 'sr-only' => ! $mark])><input type="{{ $type }}" name="{{ $name }}" value="{{ $person['id'] }}" @checked($checked) @disabled($person['disabled'] ?? false) data-action="{{ $action }}"></span>
</label>
