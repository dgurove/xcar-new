{{-- «Кто вывозит» (17.10.2026, владелец: «админ должен это нажать»): логист — регионы (исполнителя, расстояние и цену он
     ставит сам), наши люди ПРАЙМ или другой менеджер (`Partner`, владелец: «может быть кто угодно из других менеджеров, кроме
     менеджера сделки»). Менеджера сделки здесь нет: что он забирает сам, решает «Принять» — и это уже не логистика. Одна форма в CRM (карточка «Вывоз»,
     окошко строки) и в логистике (карточка задачи). Кто именно — своё поле у каждого вида (`who[logist|manager|staff]`, сервер
     берёт поле выбранного вида: без JS скрытые панели не перебивают выбор). «Документы СДЭКом» — так же своей галкой у вида
     (`cdek[вид]`; владелец 17.10.2026: «нужно выбирать, а не всем»): у логиста (регионы) отмечена, у менеджера и наших — нет;
     у перевода парковки её нет. action — куда отправить: CRM `/offers/{n}/pickup`, логистика `/transports/{id}/executor`. --}}
@props(['transport' => null, 'offer' => null, 'action', 'prefix' => 'assign'])
@php
    use App\Logistics\Choices;
    use App\Logistics\Executor;
    $logists = Choices::logists();
    $staff = Choices::staff();
    $partners = Choices::partners($transport ?? $offer);
    $kind = old('executor', $transport?->executor && $transport->executor !== Executor::Manager ? $transport->executor->value : (count($logists) ? Executor::Logist->value : Executor::Staff->value));
    $current = $transport?->executor === Executor::Logist ? $transport->coordinator_id : $transport?->executor_id;
    $options = array_filter([
        // Пусто — сегмент всё равно есть: «+ Новый логист / сотрудник» в шторке выбора (18.10.2026).
        Executor::Logist->value => 'Логист',
        Executor::Staff->value => 'Наши',
        Executor::Partner->value => count($partners) ? 'Менеджер' : null,
    ]);
    $pickup = ! $transport || $transport->isPickup();
    // Галка вида: что прислали с ошибкой, иначе как у задачи (если вид тот же), иначе — только логисту.
    $cdekOn = fn (string $k) => old("cdek.$k") !== null ? (bool) old("cdek.$k") : ($transport?->executor?->value === $k ? $transport->docs_by_cdek : $k === Executor::Logist->value);
@endphp
<form method="post" action="{{ $action }}" {{ $attributes->class(['flex flex-col gap-4']) }} data-controller="reveal">
    @csrf
    {{ $slot }}
    @if ($options)
        <x-ui.segment name="executor" :options="$options" :value="$kind" pick/>
        <div class="contents" data-reveal-target="pane" data-reveal-key="logist" @if ($kind !== 'logist') hidden @endif>
            <div class="fields">
                <x-people.pick name="who[logist]" label="Логист" kind="logist" :people="\App\Support\People::rows(\App\Users\User::whereKey(array_keys($logists))->orderBy('name')->get())" :value="$kind === 'logist' ? ($current ?? array_key_first($logists)) : array_key_first($logists)" title="Логист"/>
            </div>
            @if ($pickup)<input type="hidden" name="cdek[logist]" value="0"><x-ui.check name="cdek[logist]" :checked="$cdekOn('logist')">Документы СДЭКом</x-ui.check>@endif
        </div>
        <div class="contents" data-reveal-target="pane" data-reveal-key="staff" @if ($kind !== 'staff') hidden @endif>
            <div class="fields">
                <x-people.pick name="who[staff]" label="Кто едет" kind="driver" :people="\App\Support\People::rows(\App\Users\User::whereKey(array_keys($staff))->orderBy('name')->get())" :value="$kind === 'staff' ? $current : null" title="Кто едет"/>
            </div>
            @if ($pickup)<input type="hidden" name="cdek[staff]" value="0"><x-ui.check name="cdek[staff]" :checked="$cdekOn('staff')">Документы СДЭКом</x-ui.check>@endif
        </div>
        <div class="contents" data-reveal-target="pane" data-reveal-key="partner" @if ($kind !== 'partner') hidden @endif>
            <div class="fields">
                <x-ui.field name="who[partner]" id="{{ $prefix }}-partner" label="Кто едет" :options="$partners" placeholder="Выберите" :value="$kind === 'partner' ? $current : null"/>
            </div>
            @if ($pickup)<input type="hidden" name="cdek[partner]" value="0"><x-ui.check name="cdek[partner]" :checked="$cdekOn('partner')">Документы СДЭКом</x-ui.check>@endif
        </div>
    @else
        <x-ui.empty>Пока некому: позовите логиста или сотрудника ссылкой в «Пользователях»</x-ui.empty>
    @endif
    @error('executor')<p class="field-error">{{ $message }}</p>@enderror
    @error('executor_id')<p class="field-error">{{ $message }}</p>@enderror
    @if ($options)
        <x-ui.button type="submit" variant="primary" block>{{ $transport?->executor && $transport->executor !== Executor::Manager ? 'Сохранить' : 'Назначить' }}</x-ui.button>
    @endif
</form>
