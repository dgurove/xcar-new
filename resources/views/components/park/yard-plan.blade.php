{{-- Схема парковки (`Park\Plan`): ряды мест в метрах, SVG с сервера. Занятое место — машина сверху: марка и цифры госномера,
     цвет — светофор простоя (`Park\Idle::tone`); свободное — номер. Масштаб и выбор — `yard_plan_controller`.
     mode: tile — обзор в плитке «Парковок» (без подписей машин), full — страница схемы, pick — выбор места в шторке.
     Числа SVG — метры. cars — ТС на местах (место → Vehicle с `car.brand`), current — место, которое отметить (ТС в шторке «Переставить»). --}}
@props(['yard', 'cars' => collect(), 'mode' => 'full', 'current' => null])
@php
    use App\Park\Plan;
    $rows = $yard->plan();
    [$x0, $y0, $x1, $y1] = Plan::bounds($rows) ?? [0, 0, 10, 10];
    $pad = 2.5;
    $vb = implode(' ', [round($x0 - $pad, 2), round($y0 - $pad, 2), round($x1 - $x0 + 2 * $pad, 2), round($y1 - $y0 + 2 * $pad, 2)]);
    $fit = fn (string $s, float $room, float $em) => mb_strlen($s) * $em * 0.56 <= $room ? $s : mb_substr($s, 0, max(1, (int) floor($room / ($em * 0.56)) - 1)).'…';
    $label = $mode !== 'tile';
@endphp
<svg viewBox="{{ $vb }}" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg" {{ $attributes->merge(['class' => 'plan plan-'.$mode]) }} data-yard-plan-target="svg" data-box="{{ $vb }}" role="img" aria-label="Схема «{{ $yard->name }}»">
    @foreach ($rows as $row)
        @php [$bx0, $by0, $bx1, $by1] = Plan::box($row); @endphp
        @if ($row['name'] !== '' && $label)
            @if ($row['dir'] === 'h')<text class="plan-row" x="{{ $bx0 - 0.9 }}" y="{{ ($by0 + $by1) / 2 }}">{{ $row['name'] }}</text>
            @else<text class="plan-row" x="{{ ($bx0 + $bx1) / 2 }}" y="{{ $by0 - 0.9 }}">{{ $row['name'] }}</text>@endif
        @endif
        @foreach (Plan::spots($row) as $s)
            @php
                $v = $cars[$s['code']] ?? null;
                $tone = $v ? (\App\Park\Idle::tone($v->accepted_at ? (int) $v->accepted_at->diffInDays(now()) : null) ?? 'ok') : null;
                $cx = $s['x'] + $s['w'] / 2;
                $em = min($s['w'], $s['h']) * 0.26;
            @endphp
            <g class="spot{{ $v ? ' spot-car tone-'.$tone : ' spot-free' }}{{ $current === $s['code'] ? ' is-current' : '' }}" data-spot="{{ $s['code'] }}"
               @if ($v) data-vehicle="{{ $v->id }}" @if ($label) data-find="{{ mb_strtolower(implode(' ', array_filter([$v->plate, $v->ref, $v->vin, $v->brand?->name]))) }}" @endif @endif>
                <rect class="spot-bg" x="{{ $s['x'] + 0.08 }}" y="{{ $s['y'] + 0.08 }}" width="{{ $s['w'] - 0.16 }}" height="{{ $s['h'] - 0.16 }}" rx="0.35"/>
                @if ($v)
                    <rect class="spot-body" x="{{ $s['x'] + 0.3 }}" y="{{ $s['y'] + 0.3 }}" width="{{ $s['w'] - 0.6 }}" height="{{ $s['h'] - 0.6 }}" rx="{{ min($s['w'], $s['h']) * 0.22 }}"/>
                    {{-- Простой — полосой у «носа» машины, а не цветом всей машины: долгих на парковке много, красное поле ничего не выделяет. --}}
                    @if ($tone !== 'ok')
                        @if ($s['h'] >= $s['w'])<rect class="spot-idle" x="{{ $s['x'] + 0.55 }}" y="{{ $s['y'] + 0.45 }}" width="{{ $s['w'] - 1.1 }}" height="0.32" rx="0.16"/>
                        @else<rect class="spot-idle" x="{{ $s['x'] + $s['w'] - 0.77 }}" y="{{ $s['y'] + 0.55 }}" width="0.32" height="{{ $s['h'] - 1.1 }}" rx="0.16"/>@endif
                    @endif
                    @if ($label)
                        @php
                            $brand = trim(preg_replace('/\s*\(.*\)/u', '', $v->brand?->name ?? '')) ?: ($v->ref ?? 'ТС');
                            $digits = mb_substr(preg_replace('/\D+/', '', (string) $v->plate), 0, 3) ?: null;
                            $cy = $s['y'] + $s['h'] / 2;
                            // Место поперёк (ряд вниз) — номер слева, марка по центру остального; вдоль — номер сверху.
                            $across = $s['w'] > $s['h'];
                            $tx = $across ? $cx + $em * 0.6 : $cx;
                            $room = $across ? $s['w'] - 1.2 - $em * 1.2 : $s['w'] - 0.8;
                        @endphp
                        @if ($across)<text class="spot-num on-car" x="{{ $s['x'] + 0.4 + $em * 0.7 }}" y="{{ $cy + $em * 0.3 }}" font-size="{{ $em * 0.8 }}">{{ $s['num'] }}</text>
                        @else<text class="spot-num on-car" x="{{ $cx }}" y="{{ $s['y'] + 0.3 + $em * 1.5 }}" font-size="{{ $em * 0.8 }}">{{ $s['num'] }}</text>@endif
                        <text class="spot-brand" x="{{ $tx }}" y="{{ $cy + ($digits ? 0 : $em * 0.35) }}" font-size="{{ $em }}">{{ $fit($brand, $room, $em) }}</text>
                        @if ($digits)<text class="spot-plate" x="{{ $tx }}" y="{{ $cy + $em * 1.15 }}" font-size="{{ $em * 0.85 }}">{{ $digits }}</text>@endif
                    @endif
                @elseif ($label)
                    <text class="spot-num" x="{{ $cx }}" y="{{ $s['y'] + $s['h'] / 2 + $em * 0.35 }}" font-size="{{ $em }}">{{ $s['num'] }}</text>
                @endif
            </g>
        @endforeach
    @endforeach
</svg>
