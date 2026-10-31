{{-- Схема парковки (`Park\Plan`): ряды мест в метрах, SVG с сервера. Занятое место — значок типа ТС (`x-park.plan-car`, те же,
     что в таблицах; 31.10.2026, владелец: «не пихать названия и номера, просто иконки»), свободное — номер. Простой не
     выделяется (владелец: «чем дольше стоит, тем больше заработаем — не оценивать как хорошо или плохо»). Масштаб и выбор —
     `yard_plan_controller`. Холст — пропорцией схемы (`aspect-ratio`).
     mode: tile — обзор в плитке «Парковок», full — страница схемы, pick — выбор места в шторке.
     Числа SVG — метры. cars — ТС на местах (место → Vehicle с `car`), current — место, которое отметить. --}}
@props(['yard', 'cars' => collect(), 'mode' => 'full', 'current' => null])
@php
    use App\Park\Plan;
    $rows = $yard->plan();
    [$x0, $y0, $x1, $y1] = Plan::bounds($rows) ?? [0, 0, 10, 10];
    $pad = 2;
    [$vx, $vy, $vw, $vh] = [round($x0 - $pad, 2), round($y0 - $pad, 2), round($x1 - $x0 + 2 * $pad, 2), round($y1 - $y0 + 2 * $pad, 2)];
    $label = $mode !== 'tile';
@endphp
<svg viewBox="{{ "$vx $vy $vw $vh" }}" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => 'plan plan-'.$mode]) }}
     style="aspect-ratio: {{ $vw }} / {{ $vh }}" data-yard-plan-target="svg" data-box="{{ "$vx $vy $vw $vh" }}" role="img" aria-label="Схема «{{ $yard->name }}»">
    @foreach ($rows as $row)
        @php [$bx0, $by0, $bx1, $by1] = Plan::box($row); @endphp
        @if ($row['name'] !== '' && $label)
            @if ($row['dir'] === 'h')<text class="plan-row" x="{{ $bx0 - 0.8 }}" y="{{ ($by0 + $by1) / 2 }}">{{ $row['name'] }}</text>
            @else<text class="plan-row" x="{{ ($bx0 + $bx1) / 2 }}" y="{{ $by0 - 0.8 }}">{{ $row['name'] }}</text>@endif
        @endif
        @foreach (Plan::spots($row) as $s)
            @php $v = $cars[$s['code']] ?? null; @endphp
            <g class="spot{{ $v ? ' spot-car' : ' spot-free' }}{{ $current === $s['code'] ? ' is-current' : '' }}" data-spot="{{ $s['code'] }}"
               @if ($v) data-vehicle="{{ $v->id }}" @if ($label) data-find="{{ mb_strtolower(implode(' ', array_filter([$v->plate, $v->ref, $v->vin, $v->brand?->name]))) }}" @endif @endif>
                <rect class="spot-bg" x="{{ $s['x'] + 0.08 }}" y="{{ $s['y'] + 0.08 }}" width="{{ $s['w'] - 0.16 }}" height="{{ $s['h'] - 0.16 }}" rx="0.3"/>
                @if ($v)
                    <x-park.plan-car :spot="$s" :category="$v->category"/>
                    @if ($label)<text class="spot-num is-small" x="{{ $s['x'] + 0.45 }}" y="{{ $s['y'] + 0.75 }}">{{ $s['num'] }}</text>@endif
                @elseif ($label)
                    <text class="spot-num" x="{{ $s['x'] + $s['w'] / 2 }}" y="{{ $s['y'] + $s['h'] / 2 }}" font-size="{{ min($s['w'], $s['h']) * 0.3 }}">{{ $s['num'] }}</text>
                @endif
            </g>
        @endforeach
    @endforeach
</svg>
