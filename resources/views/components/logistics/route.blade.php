{{-- Маршрут вывоза из отрезков (20.10.2026, владелец: «в регионе до стоянки, потом межрегион, потом стоянка, потом пункт
     назначения — у каждого отрезка свой исполнитель»; `Logistics\Route`). Точки лесенкой: откуда, стоянки, куда; под точкой —
     отрезок до следующей строкой группы: исполнитель (нет — оранжевым), цена ведущим и логисту, межрегион, где он сейчас.
     Нажатие отрезка — шторка «Отрезок N» (`logist-{id}`: исполнитель, км, цена, исполнителю, межрегион, комментарий; заранее
     — у отрезка «Впереди»), «+ Стоянка» под отрезком — шторка стоянки (`stop-{id}`, `AddStop`), «×» у стоянки, за которой
     отрезок ещё впереди, — убрать её (`RemoveStop`). Последняя точка — цель маршрута: нажатием окно «Куда везти» (`route-{id}`
     текущего отрезка, правит последний). `readonly` — CRM (маршрут правят в логистике), там же нет цен. --}}
@props(['t', 'me', 'readonly' => false])
@php
    use App\Logistics\Point;
    use App\Logistics\Route;
    use App\Logistics\TransportState as S;
    use App\Support\Money;
    $legs = $t->chain();
    $lead = $me->leadsLogistics();
    $mine = $t->hasLogist($me);
    $edit = ! $readonly && ($lead || $mine) && $t->logist && ! $t->isOrder();
    $prices = ! $readonly && ($lead || $mine);
    $n = $legs->count();
    // Точка p: машина её прошла — «done», стоит в ней или едет из неё — «current», впереди — «later».
    $status = function (int $p) use ($legs, $n) {
        $reached = $p === 0 || $legs[$p - 1]->state === S::Delivered;
        $left = $p < $n && $legs[$p]->state->picked();

        return match (true) {
            $p === $n && $reached => 'done',
            $left => 'done',
            $reached && $legs->every(fn ($l) => $l->state !== S::Cancelled) => 'current',
            default => 'later',
        };
    };
    $state = function ($leg) {
        return match (true) {
            $leg->state === S::Planned => 'впереди',
            $leg->state === S::Delivered => 'привёз '.\App\Support\Ago::stamp($leg->delivered_at, time: true),
            $leg->state === S::Moving => 'в пути',
            $leg->state === S::Cancelled => 'отменён',
            default => $leg->next(),
        };
    };
    $last = $legs->last();
@endphp
<h3 {{ $attributes->class(['list-head']) }}>Маршрут</h3>
<div class="steps">
    @foreach ($legs as $i => $leg)
        @php
            $point = $i === 0 ? Route::fromLabel($leg) : 'Стоянка '.($leg->fromDepot?->name ?? '');
            $sub = $i === 0 ? ($leg->fromAddress() ?? $leg->city()) : implode(', ', array_filter([$leg->fromDepot?->settlement?->name, $leg->fromDepot?->address]));
            $before = $legs[$i - 1] ?? null;
            // Стоянку убрать можно, пока отрезок за ней не начался, а машину до неё не привезли.
            $removable = $edit && $before && $leg->state === S::Planned && ($before->isOpen() || $before->state === S::Planned);
            $legEdit = $edit && Route::untouched($leg) && $leg->state !== S::Cancelled;
            $here = $leg->current && $n > 1;
        @endphp
        <div class="step step--{{ $status($i) }}">
            <span class="step-dot">@if ($status($i) === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
            <div class="step-body">
                <div class="step-head">
                    <span class="step-title min-w-0 flex-1 !text-base break-words">{{ $point }}</span>
                    @if ($removable)
                        <form method="post" action="/transports/{{ $before->id }}/stop" class="shrink-0" data-turbo-confirm="Убрать «{{ $point }}» из маршрута?" data-turbo-confirm-label="Убрать">
                            @csrf @method('delete')<button class="text-sm text-ink-muted" aria-label="Убрать стоянку"><x-ui.icon name="x" class="size-4"/></button>
                        </form>
                    @endif
                </div>
                @if ($sub)<p class="step-hint break-words">{{ $sub }}</p>@endif
                {{-- Отрезок до следующей точки. --}}
                @php
                    $who = $leg->executor ? $leg->executor->shortName() : 'Без исполнителя';
                    $facts = array_filter([$prices && $leg->cost ? Money::rub((int) $leg->cost) : null, $leg->reach ? mb_lcfirst($leg->reach->label()) : null, $state($leg)]);
                    $body = '<span class="nums w-5 shrink-0 text-sm text-ink-dim">'.($i + 1).'</span><span class="min-w-0 flex-1"><span class="block '.($leg->executor ? '' : 'text-urgent').'">'.e($who).'</span>'
                        .'<span class="block text-sm text-ink-muted">'.e(implode(', ', $facts)).'</span></span>';
                @endphp
                <div @class(['list mt-2', '[&>.row]:bg-accent-soft' => $here])>
                    @if ($legEdit)
                        <button type="button" class="row w-full items-center gap-3 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="logist-{{ $leg->id }}:open">{!! $body !!}<x-ui.chevron/></button>
                    @else
                        <div class="row items-center gap-3">{!! $body !!}</div>
                    @endif
                </div>
                @if ($edit && ($leg->isOpen() || $leg->state === S::Planned))
                    <button type="button" class="mt-2 text-sm text-accent-text" data-controller="emit" data-action="emit#send" data-emit-event-param="stop-{{ $leg->id }}:open">+ Стоянка</button>
                @endif
            </div>
        </div>
    @endforeach
    {{-- Цель маршрута — «куда» последнего отрезка. --}}
    @php $goal = Route::toLabel($last); @endphp
    <div class="step step--{{ $status($n) }}">
        <span class="step-dot">@if ($status($n) === 'done')<x-ui.icon name="check" class="size-3"/>@endif</span>
        <div class="step-body">
            @if (! $readonly && $t->isOpen() && $t->isHandledBy($me) && $last->destination !== Point::Depot)
                <button type="button" class="step-head w-full text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="route-{{ $t->id }}:open">
                    <span @class(['step-title min-w-0 flex-1 !text-base break-words', 'text-urgent' => ! $last->routeLabel()])>{{ $last->routeLabel() ? $goal : 'Куда везти: пока неизвестно' }}</span><x-ui.chevron/>
                </button>
            @else
                <div class="step-head"><span class="step-title min-w-0 flex-1 !text-base break-words">{{ $goal }}</span></div>
            @endif
        </div>
    </div>
</div>

@if ($edit)
    @foreach ($legs as $i => $leg)
        @continue(! (Route::untouched($leg) && $leg->state !== S::Cancelled) && ! ($leg->isOpen() || $leg->state === S::Planned))
        {{-- Отрезок: исполнитель логиста, расстояние, цена, исполнителю, межрегион, комментарий. --}}
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
        {{-- «+ Стоянка» после отрезка: делит его стоянкой логиста. --}}
        @if ($leg->isOpen() || $leg->state === S::Planned)
            <div data-controller="sheet" data-action="stop-{{ $leg->id }}:open@window->sheet#open" class="contents">
                <x-ui.sheet :id="'stop-'.$leg->id" title="Стоянка в маршруте">
                    <form method="post" action="/transports/{{ $leg->id }}/stops" class="flex flex-col gap-4">
                        @csrf
                        <div class="fields"><x-people.pick name="depot_id" label="Стоянка" kind="depot" :people="\App\Support\People::depotsOf($leg->logist)" title="Стоянка" :owner="$leg->logist_id" required/></div>
                        <x-ui.sheet-foot>Поставить стоянку</x-ui.sheet-foot>
                    </form>
                </x-ui.sheet>
            </div>
        @endif
    @endforeach
@endif
