{{-- «Кто вывозит» (17.10.2026, владелец: «админ должен это нажать»): логист — регионы (исполнителя, расстояние и цену он
     ставит сам), менеджер, которому подтвердили сделку (только он), наши люди ПРАЙМ. Одна форма в CRM (карточка «Вывоз»,
     окошко строки) и в логистике (карточка задачи). Кто именно — своё поле у каждого вида (`who[logist|manager|staff]`, сервер
     берёт поле выбранного вида: без JS скрытые панели не перебивают выбор). Документы СДЭКом — сами по печати исполнителя (`User::seals`),
     переключает админ в задаче. action — куда отправить: CRM `/offers/{n}/pickup`, логистика `/transports/{id}/executor`. --}}
@props(['transport' => null, 'offer' => null, 'action', 'prefix' => 'assign'])
@php
    use App\Logistics\Choices;
    use App\Logistics\Executor;
    $manager = Choices::managerOf($transport ?? $offer);
    $logists = Choices::logists();
    $staff = Choices::staff();
    $kind = old('executor', $transport?->executor?->value ?? ($manager ? Executor::Manager->value : (count($logists) ? Executor::Logist->value : Executor::Staff->value)));
    $current = $transport?->executor === Executor::Logist ? $transport->coordinator_id : $transport?->executor_id;
    $options = array_filter([
        Executor::Logist->value => count($logists) ? 'Логист' : null,
        Executor::Manager->value => $manager ? $manager->shortName() : null,
        Executor::Staff->value => count($staff) ? 'Наши' : null,
    ]);
@endphp
<form method="post" action="{{ $action }}" {{ $attributes->class(['flex flex-col gap-4']) }} data-controller="reveal">
    @csrf
    {{ $slot }}
    @if ($options)
        <x-ui.segment name="executor" :options="$options" :value="$kind" pick/>
        <div class="fields" data-reveal-target="pane" data-reveal-key="logist" @if ($kind !== 'logist') hidden @endif>
            <x-ui.field name="who[logist]" id="{{ $prefix }}-logist" label="Логист" :options="$logists" :value="$kind === 'logist' ? ($current ?? array_key_first($logists)) : array_key_first($logists)"/>
        </div>
        @if ($manager)
            <div class="contents" data-reveal-target="pane" data-reveal-key="manager" @if ($kind !== 'manager') hidden @endif>
                <input type="hidden" name="who[manager]" value="{{ $manager->id }}">
            </div>
        @endif
        <div class="fields" data-reveal-target="pane" data-reveal-key="staff" @if ($kind !== 'staff') hidden @endif>
            <x-ui.field name="who[staff]" id="{{ $prefix }}-staff" label="Кто едет" :options="$staff" placeholder="Выберите" :value="$kind === 'staff' ? $current : null"/>
        </div>
    @else
        <x-ui.empty>Пока некому: позовите логиста или сотрудника ссылкой в «Пользователях»</x-ui.empty>
    @endif
    @error('executor')<p class="field-error">{{ $message }}</p>@enderror
    @error('executor_id')<p class="field-error">{{ $message }}</p>@enderror
    @if ($options)
        <x-ui.button type="submit" variant="primary" block>{{ $transport?->executor ? 'Сохранить' : 'Назначить' }}</x-ui.button>
    @endif
</form>
