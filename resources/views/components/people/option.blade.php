{{-- Человек строкой выбора — одна разметка на всё приложение: `x-people.pick`, «Кто платит» (`x-billing.payer-pick`),
     «Кто заберёт» (`x-deal.receiver-sheet`); тот же кусок отдаёт сервер для только что заведённого (`PeopleController`).
     Аватар, имя, подпись, галка; `action` — что делает выбор (по умолчанию — шторка `x-people.pick`). --}}
@props(['person', 'name', 'checked' => false, 'type' => 'radio', 'action' => 'change->people-pick#choose'])
<label class="row row-check people-option" data-people-option data-search-text="{{ mb_strtolower($person['name'].' '.($person['sub'] ?? '')) }}" @isset($person['address']) data-address="{{ $person['address'] }}" @endisset>
    @if ($person['user'] ?? null)<x-ui.avatar :user="$person['user']" :size="36"/>@else<x-ui.avatar :name="$person['name']" :size="36"/>@endif
    <span class="min-w-0 flex-1">
        <span class="block truncate" data-people-name>{{ $person['name'] }}</span>
        @if ($person['sub'] ?? null)<span class="block truncate text-sm text-ink-muted">{{ $person['sub'] }}</span>@endif
    </span>
    <span class="check"><input type="{{ $type }}" name="{{ $name }}" value="{{ $person['id'] }}" @checked($checked) data-action="{{ $action }}"></span>
</label>
