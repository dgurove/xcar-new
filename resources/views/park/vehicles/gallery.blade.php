{{-- Лента кадров ТС с пилюлями стадий: при приёме, при погрузке, при выдаче, хранение, из письма. Стадия — в адресе `stage`. --}}
@php
    $stage = request()->query('stage');
    $all = $vehicle->photos();
    $counts = $all->countBy(fn ($m) => (string) ($m->getCustomProperty('stage') ?? 'mail'));
    $stages = ['intake' => 'При приёме', 'pickup' => 'При погрузке', 'release' => 'При выдаче', 'storage' => 'Хранение', 'mail' => 'Из письма'];
    $shown = $stage && isset($stages[$stage]) ? $all->filter(fn ($m) => (string) ($m->getCustomProperty('stage') ?? 'mail') === $stage) : $all;
@endphp
<div id="gallery-wrap">
    {{-- Пилюли стадий — всегда, когда кадры есть: «Из письма» должно читаться и при одной стадии. --}}
    @if ($all->isNotEmpty())
        <div class="mb-3 flex flex-wrap gap-1.5">
            <x-ui.pill :href="request()->fullUrlWithQuery(['stage' => null])" :current="!$stage" class="!min-h-0 !py-1 text-xs" data-turbo-action="replace">Все <span class="nums text-ink-dim">{{ $all->count() }}</span></x-ui.pill>
            @foreach ($stages as $key => $label)
                @if ($counts->get($key))<x-ui.pill :href="request()->fullUrlWithQuery(['stage' => $key])" :current="$stage === $key" class="!min-h-0 !py-1 text-xs" data-turbo-action="replace">{{ $label }} <span class="nums text-ink-dim">{{ $counts->get($key) }}</span></x-ui.pill>@endif
            @endforeach
        </div>
    @endif
    <x-ui.photos :photos="$shown" :hide="false" :main="false" grid/>
</div>
