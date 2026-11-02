{{-- Шаг «Нужно указать парковку»: ТС заведена без площадки (по письмам или по факту), стоит там со дня приёма. Своя форма
     (`SetYard`): формы текущего шага вокруг таймлайна больше нет. Вместе с парковкой — фактический день приёма (02.11.2026):
     принята по нашему письму, а письмо уходит через несколько дней после приёма; дата — тому, кто видит деньги (от неё
     считается хранение). `compact` — плашкой в карточке строки «Наличия», без места: место — потом схемой или «Переставить». --}}
@php
    $compact ??= false;
    $dated = auth()->user()->parkMoney() && $vehicle->accepted_at && ! $vehicle->storage_billed_until;
@endphp
@if ($compact)
    <section class="flex flex-col gap-2 rounded-(--radius-l) bg-surface-2 p-3">
        <h2 class="text-base font-medium">{{ \App\Park\Tasks::LABELS['yard'] }}</h2>
@endif
<form method="post" action="/cars/{{ $vehicle->id }}/yard">
    @csrf
    <div @class(['grid grid-cols-2 gap-2', 'sm:grid-cols-3' => ! $compact && $dated, 'mt-2' => ! $compact]) data-controller="spots" data-spots-map-value="{{ json_encode($yardRows ?? []) }}">
        <x-park.yard-field :yards="$yards" placeholder="—" :value="old('yard_id')" required data-spots-target="yard" data-action="change->spots#sync"/>
        @if ($dated)
            <x-ui.field name="accepted_at" label="Принята" type="datetime-local" :value="old('accepted_at', $vehicle->accepted_at->format('Y-m-d\TH:i'))" :max="now()->format('Y-m-d\TH:i')" required/>
        @endif
        @unless ($compact)
            <x-ui.field name="spot" label="Место" list="spots-list" autocapitalize="characters"/>
            <datalist id="spots-list" data-spots-target="list"></datalist>
            @include('park.yards.plan-pick-frame', ['pick' => $vehicle->id, 'at' => null])
        @endunless
    </div>
    <div class="mt-2 flex"><button class="btn btn-s btn-accent flex-1">Указать</button></div>
</form>
@if ($compact)
    </section>
@endif
