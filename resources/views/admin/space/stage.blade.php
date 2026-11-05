{{-- Этап — столбец: над названием парят люди — постоянный ответственный полным именем, у общих этапов (менеджеры,
     поставщики, вывоз) первые лица с числом задач; название крупно без плашки, сколько всего и сколько горит; сразу под ним
     подэтапы. Этап, где только ждём («Ждём поставщика»), — тише. Шапки — строка сетки доски (`subgrid`), прижаты к низу: названия и первые подэтапы стоят одной линией. --}}
<section @class(['conveyor-stage', 'is-quiet' => $stage['quiet']]) data-stage="{{ $stage['key'] }}">
    <div class="stage-head">
        @if ($stage['people']->isNotEmpty())
            <div class="stage-people">
                @foreach ($stage['people'] as $p)
                    @php
                        $name = match ($p['type']) {
                            'user' => isset($p['n']) ? $p['model']->firstName() : $p['model']->name,
                            'vendor' => null,
                            default => $p['label'],
                        };
                    @endphp
                    <span class="stage-person" @if ($p['type'] === 'vendor') title="{{ $p['model']->name }}" @endif>
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
        @endif
        <div class="stage-line">
            <h2 class="stage-title">{{ $stage['title'] }}</h2>
            <span class="stage-n" data-step="stage:{{ $stage['key'] }}"><span data-count="{{ $stage['total'] }}">{{ $stage['total'] }}</span></span>
            @if ($stage['hot'])
                <span class="hot-pill" title="Горят"><x-ui.icon name="fire" class="size-3.5"/>{{ $stage['hot'] }}</span>
            @endif
        </div>
    </div>
    <div class="stage-steps">
        @foreach ($stage['steps'] as $step)
            @include('admin.space.step')
        @endforeach
    </div>
</section>
