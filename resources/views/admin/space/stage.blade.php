{{-- Этап — столбец: над названием парят люди — постоянный ответственный полным именем (двое — по имени: ряд в одну строку), у общих этапов (менеджеры,
     поставщики, вывоз) первые лица с числом задач; название крупно без плашки, сколько всего и сколько горит; сразу под ним
     подэтапы. Этап, где только ждём («Ждём поставщика»), — тише. Шапки — строка сетки доски (`subgrid`) одной высоты: ряд людей
     в строку, название в две строки — верх названий и первые подэтапы стоят одной линией. --}}
<section @class(['conveyor-stage', 'is-quiet' => $stage['quiet']]) data-stage="{{ $stage['key'] }}">
    <div class="stage-head">
        {{-- Ряд людей есть всегда: без людей пустой той же высоты — шапки этапов одной высоты. --}}
        <div class="stage-people">
            @foreach ($stage['people'] as $p)
                @php
                    $name = match ($p['type']) {
                        // Троим и больше в строке тесно — у них лица с числом, имя в подсказке.
                        'user' => match (true) { $stage['people']->count() > 2 => null, isset($p['n']) || $stage['people']->count() > 1 => $p['model']->firstName(), default => $p['model']->name },
                        'vendor' => null,
                        default => $p['label'],
                    };
                @endphp
                <span class="stage-person" data-who="{{ $p['key'] }}" @if ($p['type'] !== 'icon' && ! $name) title="{{ $p['model']->name }}" @endif>
                    @include('admin.space.face', ['who' => $p])
                    @if ($name)
                        <span class="person-name">{{ $name }}</span>
                    @endif
                    @isset($p['n'])
                        <span @class(['person-n', 'is-late' => $p['late']])>{{ $p['n'] }}</span>
                    @endisset
                </span>
            @endforeach
            @if ($stage['more'])
                <span class="people-more">+{{ $stage['more'] }}</span>
            @endif
        </div>
        <div class="stage-line">
            <h2 class="stage-title">{{ $stage['title'] }}</h2>
            <span class="stage-n" data-step="stage:{{ $stage['key'] }}"><span data-count="{{ $stage['total'] }}">{{ $stage['total'] }}</span></span>
            <span class="hot-pill" title="Горят" @if (! $stage['hot']) hidden @endif><x-ui.icon name="fire" class="size-3.5"/><span data-hot>{{ $stage['hot'] }}</span></span>
        </div>
    </div>
    <div class="stage-steps">
        @foreach ($stage['steps'] as $step)
            @include('admin.space.step')
        @endforeach
    </div>
</section>
