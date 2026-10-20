{{-- Маршрут вывоза — панелью, как маршрут в Google Картах (20.10.2026, владелец: «как это сделали бы в нормальном
     приложении»; модель — `Logistics\Route`). Точки — значком на линии: старт кольцом, стоянка точкой, цель меткой; название и
     адрес. Между точками — отрезок на самой линии: исполнитель (нет — «Назначить исполнителя»), цена ведущим и логисту,
     межрегион, где он сейчас; нажатие — шторка отрезка (`logist-{id}`). Линия пройденного — сплошная лаймовая, впереди —
     пунктир; где машина — грузовик (стоит — у точки, едет — на линии отрезка).
     Правка — «Изменить / Готово» в заголовке (галка `data-turbo-permanent`: морф после действия режим не сбрасывает): у
     стоянок, до которых машину не довезли, «≡» (перетаскивание, `route_stops_controller` → `ReorderStops`) и «×»
     (`RemoveStop`), нажатие на название — выбор другой (`ReplaceStop`); перед целью — «Добавить стоянку» (`AddStop` у последнего
     отрезка). Маршрута ещё нет (ни стоянок, ни исполнителя) — режим правки сразу. Цель — окно «Куда везти» (`route-{id}`).
     `readonly` — CRM: смотреть, без правки и цен. --}}
@props(['t', 'me', 'readonly' => false])
@php
    use App\Logistics\Point;
    use App\Logistics\Route;
    use App\Logistics\TransportState as S;
    use App\Support\Money;
    $legs = $t->chain()->values();
    $n = $legs->count();
    $lead = $me->leadsLogistics();
    $edit = ! $readonly && ($lead || $t->hasLogist($me)) && $t->logist && ! $t->isOrder();
    $prices = ! $readonly && ($lead || $t->hasLogist($me));
    $first = $legs->first();
    $last = $legs->last();
    $cancelled = $legs->contains(fn ($l) => $l->state === S::Cancelled);
    // Где машина: едет — на линии отрезка; стоит — в точке перед текущим отрезком; маршрут кончился — в цели.
    $moving = $legs->first(fn ($l) => $l->state === S::Moving);
    $carAt = $cancelled || $moving ? null : ($last->state === S::Delivered ? $n : (int) $legs->search(fn ($l) => $l->current));
    // Точка p пройдена, если машина уехала из неё (у цели — привезли).
    $passed = fn (int $p) => $p < $n ? $legs[$p]->state->picked() : $last->state === S::Delivered;
    $word = fn ($leg) => match (true) {
        $leg->state === S::Planned => 'впереди',
        $leg->state === S::Delivered => 'привёз '.\App\Support\Ago::stamp($leg->delivered_at, time: true),
        $leg->state === S::Moving => 'в пути',
        $leg->state === S::Cancelled => 'отменён',
        // Без исполнителя — говорит сама строка «Назначить исполнителя».
        in_array($leg->state, [S::New, S::Logist], true) => '',
        default => $leg->next(),
    };
    $depots = $edit ? \App\Support\People::depotsOf($t->logist) : collect();
    $autoEdit = $edit && $n === 1 && ! $last->executor_id;
    $toggleId = 'route-edit-'.$first->id;
    $place = fn ($depot) => $depot ? implode(', ', array_filter([$depot->settlement?->name, $depot->address])) : '';
@endphp
<section {{ $attributes->class(['route-panel']) }}>
    <div class="mb-2 flex items-center gap-3">
        <h3 class="list-head !m-0 flex-1">Маршрут</h3>
        @if ($edit)
            <input type="checkbox" id="{{ $toggleId }}" class="route-edit-toggle sr-only" data-turbo-permanent @checked($autoEdit)>
            <label for="{{ $toggleId }}" class="edit-card-toggle cursor-pointer"><span class="route-view-only">Изменить</span><span class="route-edit-only">Готово</span></label>
        @endif
    </div>
    <ol class="route" @if ($edit) data-controller="route-stops" data-route-stops-url-value="/transports/{{ $first->id }}/stops/order" @endif>
        {{-- Старт. --}}
        <li @class(['route-point route-point--start', 'is-passed' => $passed(0)]) data-fixed>
            <span class="route-mark"><span class="route-ring"></span></span>
            <span class="route-text"><span class="route-name">{{ Route::fromLabel($first) }}</span>@if ($sub = $first->fromAddress() ?? $first->city())<span class="route-sub">{{ $sub }}</span>@endif</span>
            @if ($carAt === 0)<span class="route-car" title="Машина здесь"><x-ui.icon name="cat-truck" class="size-5"/></span>@endif
        </li>
        @foreach ($legs as $i => $leg)
            @php
                $legEdit = $edit && Route::untouched($leg) && $leg->state !== S::Cancelled;
                $line = match (true) { $leg->state === S::Delivered => 'done', $leg->state === S::Moving => 'now', default => 'ahead' };
                $facts = array_filter([$prices && $leg->cost ? Money::rub((int) $leg->cost) : null, $leg->reach === \App\Logistics\Reach::Intercity ? 'межрегион' : null]);
                $who = $leg->executor ? ($leg->executor->is($me) ? 'вы' : $leg->executor->shortName()) : null;
                $inner = '<span class="route-text">'
                    .($who ? '<span class="route-who">'.e($who).'</span>' : '<span class="route-who '.($legEdit ? 'text-accent-text' : 'text-urgent').'">'.($legEdit ? 'Назначить исполнителя' : 'Без исполнителя').'</span>')
                    .($facts ? '<span class="route-sub">'.e(implode(', ', $facts)).'</span>' : '').'</span>'
                    .'<span class="route-state">'.e($word($leg)).'</span>';
            @endphp
            {{-- Отрезок — на линии между точками. --}}
            <li class="route-leg route-leg--{{ $line }}" data-fixed>
                <span class="route-mark">@if ($moving?->is($leg))<span class="route-car route-car--moving" title="Машина в пути"><x-ui.icon name="cat-truck" class="size-5"/></span>@endif</span>
                @if ($legEdit)
                    <button type="button" class="route-leg-body" data-controller="emit" data-action="emit#send" data-emit-event-param="logist-{{ $leg->id }}:open">{!! $inner !!}<x-ui.chevron/></button>
                @else
                    <span class="route-leg-body">{!! $inner !!}</span>
                @endif
            </li>
            {{-- Стоянка за отрезком (у последнего — цель, ниже). --}}
            @if ($i < $n - 1)
                @php
                    $movable = $edit && Route::movable($leg, $legs[$i + 1]);
                    $depot = $leg->depot;
                @endphp
                <li @class(['route-point route-point--stop', 'is-passed' => $passed($i + 1)]) data-stop="{{ $leg->depot_id }}" @unless ($movable) data-fixed @endunless>
                    <span class="route-mark"><span class="route-dot"></span></span>
                    @if ($movable)
                        <button type="button" class="route-text text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="stopsel-{{ $leg->id }}:open">
                            <span class="route-name">Стоянка {{ $depot?->name }}</span>@if ($place($depot))<span class="route-sub">{{ $place($depot) }}</span>@endif
                        </button>
                    @else
                        <span class="route-text"><span class="route-name">Стоянка {{ $depot?->name }}</span>@if ($place($depot))<span class="route-sub">{{ $place($depot) }}</span>@endif</span>
                    @endif
                    @if ($carAt === $i + 1)<span class="route-car" title="Машина здесь"><x-ui.icon name="cat-truck" class="size-5"/></span>@endif
                    @if ($movable)
                        <span class="route-edit-only flex shrink-0 items-center">
                            <span class="route-tool" data-handle aria-label="Перетащить"><x-ui.icon name="grip" class="size-5"/></span>
                            <form method="post" action="/transports/{{ $leg->id }}/stop" data-turbo-confirm="Убрать «Стоянка {{ $depot?->name }}» из маршрута?" data-turbo-confirm-label="Убрать">
                                @csrf @method('delete')<button class="route-tool" aria-label="Убрать стоянку"><x-ui.icon name="x" class="size-5"/></button>
                            </form>
                        </span>
                    @endif
                </li>
            @endif
        @endforeach
        {{-- Добавить стоянку — перед целью, пока последний отрезок не привезли. --}}
        @if ($edit && ($last->isOpen() || $last->state === S::Planned))
            <li class="route-point route-point--add route-edit-only" data-fixed>
                <span class="route-mark"><x-ui.icon name="plus" class="size-4"/></span>
                <form method="post" action="/transports/{{ $last->id }}/stops" class="route-text">
                    @csrf
                    <x-people.pick name="depot_id" kind="depot" variant="row" :people="$depots" title="Стоянка" :owner="$last->logist_id" autosubmit placeholder="Добавить стоянку"/>
                </form>
            </li>
        @endif
        {{-- Цель маршрута — «куда» последнего отрезка. --}}
        <li @class(['route-point route-point--goal', 'is-passed' => $passed($n)]) data-fixed>
            <span class="route-mark"><x-ui.icon name="map-pin" class="size-5"/></span>
            @if (! $readonly && $t->isOpen() && $t->isHandledBy($me) && $last->destination !== Point::Depot)
                <button type="button" class="route-text text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="route-{{ $t->id }}:open">
                    <span @class(['route-name', 'text-urgent' => ! $last->routeLabel()])>{{ $last->routeLabel() ?? 'Куда везти' }}</span>@unless ($last->routeLabel())<span class="route-sub">пока неизвестно</span>@endunless
                </button>
                <x-ui.chevron/>
            @else
                <span class="route-text"><span class="route-name">{{ Route::toLabel($last) }}</span></span>
            @endif
            @if ($carAt === $n)<span class="route-car" title="Машина здесь"><x-ui.icon name="cat-truck" class="size-5"/></span>@endif
        </li>
    </ol>
</section>

@if ($edit)
    @foreach ($legs as $i => $leg)
        {{-- Отрезок: межрегион, исполнитель логиста, расстояние, цена, исполнителю, комментарий. --}}
        @if (Route::untouched($leg) && $leg->state !== S::Cancelled)
            <div data-controller="sheet" data-action="logist-{{ $leg->id }}:open@window->sheet#open" class="contents">
                <x-ui.sheet :id="'logist-'.$leg->id" :title="'Отрезок '.($i + 1).': '.Route::fromLabel($leg).' → '.Route::toLabel($leg)" tall>
                    <form method="post" action="/transports/{{ $leg->id }}/logist" class="flex flex-col gap-4">
                        @csrf @method('put')
                        <x-ui.segment name="reach" :options="\App\Logistics\Reach::options()" :value="old('reach', $leg->reach?->value)"/>
                        <div class="fields">
                            <x-people.pick name="executor_id" label="Исполнитель" kind="contractor" :people="\App\Support\People::contractorsOf($leg->logist)" :value="old('executor_id', $leg->executor_id)" title="Исполнитель" link required/>
                            <x-ui.field name="distance_km" :id="'leg-km-'.$leg->id" label="Расстояние, км" inputmode="numeric" :value="old('distance_km', $leg->distance_km)"/>
                            <x-ui.field name="cost" :id="'leg-cost-'.$leg->id" label="Стоимость, ₽" :value="old('cost', $leg->cost ? (int) $leg->cost : null)"/>
                            {{-- Своему исполнителю — для её «Оплат», XCar этого не видит. --}}
                            @if ($leg->hasLogist($me))<x-ui.field name="executor_cost" :id="'leg-ecost-'.$leg->id" label="Исполнителю, ₽" :value="old('executor_cost', $leg->executor_cost ? (int) $leg->executor_cost : null)"/>@endif
                            <x-ui.field name="comment" :id="'leg-comment-'.$leg->id" label="Комментарий" type="textarea" :value="old('comment', $leg->comment)"/>
                        </div>
                        <x-ui.sheet-foot>{{ $leg->executor_id ? 'Сохранить' : 'Назначить' }}</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
        @endif
        {{-- Другая стоянка на месте этой: нажатие на название — тот же выбор стоянки; строки выбора у шторки нет. --}}
        @if ($i < $n - 1 && Route::movable($leg, $legs[$i + 1]))
            <form method="post" action="/transports/{{ $leg->id }}/stop" class="contents">
                @csrf @method('put')
                <x-people.pick name="depot_id" kind="depot" variant="row" :people="$depots" :value="$leg->depot_id" title="Стоянка" :owner="$leg->logist_id" autosubmit
                    class="[&>button.row]:hidden" data-action="stopsel-{{ $leg->id }}:open@window->sheet#open"/>
            </form>
        @endif
    @endforeach
@endif
