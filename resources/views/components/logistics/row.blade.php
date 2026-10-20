{{-- Строка «Вывоза» (18.10.2026, Катя и Лиза: «не хватает номера убытка, логотипа страховой, менеджера — кому везти»).
     Столбцы — `cols` (`TransportController::columns`, шапка — `x-logistics.head`; пусто — все, что видно человеку). Ведущим
     логистику — где продажа (`SaleState`): ищем покупателя меньше трёх суток — строка приглушена, три и больше — слово красным, строка в полный цвет;
     покупатель есть — словом цветом и менеджер чипом «кому везти». Второй этаж названия (телефон, карточка
     рядом — краткая таблица показывает только `sm:hidden`): номер убытка с логотипом, продажа или что дальше, «→ Иван П.»,
     кто вывозит. checkable — столбец галочек пачки на «Без исполнителя» (в выдаче лупы галочка — только у задачи без
     исполнителя). preset — вкладка в ссылке строки: карточке не нужно вычислять её заново. --}}
@props(['t', 'checkable' => false, 'cols' => [], 'preset' => null])
@php
    $me = auth()->user();
    $lead = $me->leadsLogistics();
    $staff = $me->isStaff();
    $has = fn (string $k) => in_array($k, $cols, true);
    $sale = $lead ? \App\Logistics\SaleState::of($t) : null;
    $deciding = $t->state === \App\Logistics\TransportState::New;
    // Шаг вне своего столбца — только когда он не тот же, что у всей вкладки («выбрать исполнителя», «у логиста»): в выдаче лупы.
    $stepHere = $has('step') || ! in_array($t->state, [\App\Logistics\TransportState::New, \App\Logistics\TransportState::Logist], true);
    // Пустой второй строки не бывает: шаг пишется, когда «куда» нет.
    // Себя исполнитель в строке не видит; у логиста без исполнителя — «у логиста».
    $who = $t->isExecutedBy($me) ? null : $t->executor;
    $logist = ! $t->executor && $t->logist && $t->logist_id !== $me->id ? $t->logist : null;
    $late = $t->isLate();
    $manager = $sale?->manager;
    // Строка — маршрут (20.10.2026, `Logistics\Route`): ключ — `route_id`, «куда» — цель маршрута (последний отрезок),
    // межрегион — если он у любого отрезка (столбец «Кому везти» на ПК), «дальше без исполнителя» — точкой у названия.
    $legs = $t->relationLoaded('routeLegs') ? $t->routeLegs : collect([$t]);
    $goal = $legs->count() > 1 ? $legs->last() : $t;
    $key = $t->route_id ?? $t->id;
    // Цель другого отрезка — без его продажи (она та же, что у строки): к менеджеру — по продаже строки.
    $goalLabel = $goal === $t ? $t->routeLabel() : match ($goal->destination) {
        \App\Logistics\Point::Keeper => \App\Logistics\Transport::keeperLabel($t->saleOffer()) ?? \App\Logistics\Point::Keeper->label(),
        null => $goal->to_address,
        default => $goal->destinationLabel(),
    };
    // Куда — одной фразой задачи (`routeLabel`); менеджер чипом, когда точка — к нему (или не выбрана у старой задачи).
    $to = $manager && in_array($goal->destination, [null, \App\Logistics\Point::Keeper], true) && $goal->reach === null ? null : $goalLabel;
    $manager = $to ? null : $manager;
    $far = $legs->contains(fn ($l) => $l->reach === \App\Logistics\Reach::Intercity);
    $ahead = ($lead || $t->hasLogist($me)) && $legs->contains(fn ($l) => $l->seq > $t->seq && $l->state === \App\Logistics\TransportState::Planned && ! $l->executor_id);
    $ref = $t->car?->claim_ref;
    $vendorId = $t->vendorId();
    // Срок обязывающего предложения (19.10.2026): горит без покупателя — меткой у названия, строка в полный цвет.
    $binding = $has('binding') && $t->forSale() ? $t->offer : null;
@endphp
<tr data-detail-key="{{ $key }}" id="transport-{{ $key }}" data-search-row @class(['is-dim' => $sale?->waiting && ! ($binding && \App\Offers\Binding::panics($binding))])>
    @if ($checkable)<td class="pick-cell">@if ($deciding)<label class="row-check" aria-label="Выбрать"><span class="check"><input type="checkbox" id="pick-transport-{{ $t->id }}" data-turbo-permanent name="transports[]" value="{{ $t->id }}" data-pick-target="box" data-action="pick#sync"></span></label>@endif</td>@endif
    <td class="grow">
        <x-ui.row-link :key="$key" :with="$preset ? ['preset' => $preset] : []"><span class="cell-title"><x-ui.cat-icon :category="$t->category()"/><span class="cell-name">{{ $t->title() }}</span><x-car.keys :count="$t->car?->keys_count"/><x-ui.links :transport="$t"/>@if ($ahead)<span class="row-signal" title="Дальше без исполнителя" aria-label="Дальше без исполнителя"><span class="size-2 rounded-full bg-urgent"></span></span>@endif @if ($binding)<x-offer.binding :offer="$binding" icon class="sm:hidden"/>@endif</span></x-ui.row-link>
        {{-- Вторая строка — одна линия (20.10.2026, владелец: «строки — чтобы быстро найти, а не печатать всю информацию»): что
             дальше цветным словом целиком и куда — режется «…». Номер убытка, путь через стоянки, кто вывозит — в карточке и
             столбцах ПК, межрегион — чипом-фильтром и в карточке; «дальше без исполнителя» — оранжевой точкой у названия. --}}
        <span class="cell-sub cell-sub--one">
            @if ($sale && $deciding)<span class="sm:hidden shrink-0 {{ $sale->tone }}">{{ $sale->word }}</span>
            @elseif ($stepHere || (! $manager && ! $to))<span @class(['shrink-0', 'sm:hidden' => $has('step'), $late ? 'text-danger' : $t->state->tone()])>{{ $late ? 'не вывезли в срок' : $t->next() }}</span>@endif
            @if ($manager)<span class="sm:hidden min-w-0 truncate">→ {{ $manager->shortName() }}</span>@elseif ($to)<span class="sm:hidden min-w-0 truncate">→ {{ $to }}</span>@endif
        </span>
    </td>
    @if ($has('client'))<td class="hidden sm:table-cell"><span @class(['text-ink-muted' => ! $t->client])>{{ $t->client?->name ?? 'XCar' }}</span></td>@endif
    @if ($has('vendor'))<td class="hidden sm:table-cell"><x-vendor.ref :vendor-id="$vendorId" :ref="$ref" cell/></td>@endif
    @if ($has('sale'))<td class="hidden sm:table-cell"><span class="{{ $sale?->tone }}">{{ $sale?->word }}</span></td>@endif
    @if ($has('binding'))<td @class(['hidden sm:table-cell nums whitespace-nowrap', 'text-danger' => $binding && \App\Offers\Binding::panics($binding), 'text-ink-muted' => ! ($binding && \App\Offers\Binding::panics($binding))])>{{ $binding?->binding_until ? \App\Offers\Binding::date($binding->binding_until) : '' }}</td>@endif
    @if ($has('to'))<td class="hidden sm:table-cell">@if ($manager)<x-ui.person :user="$manager"/>@elseif ($to)<span class="text-ink-muted">{{ $to }}</span>@endif @if ($far)<span class="block text-sm text-ink-dim">между регионами</span>@endif</td>@endif
    @if ($has('city'))<td class="col-detail-hide hidden 2xl:table-cell">@if ($city = $t->city())<x-ui.place class="whitespace-nowrap text-ink-muted">{{ $city }}</x-ui.place>@endif</td>@endif
    @if ($has('step'))<td class="hidden sm:table-cell"><span class="{{ $late ? 'text-danger' : $t->state->tone() }}">{{ $late ? 'не вывезли в срок' : $t->next() }}</span></td>@endif
    @if ($has('who'))<td class="hidden sm:table-cell">@if ($who)<x-ui.person :user="$who"/>@elseif ($logist)<span class="text-ink-muted">у логиста {{ $logist->shortName() }}</span>@endif</td>@endif
    @if ($has('created'))<td @class(['num nums whitespace-nowrap cell-dim', 'col-detail-hide hidden 2xl:table-cell' => $has('when')])>{{ \App\Support\Ago::stamp($t->created_at, time: true) }}</td>@endif
    @if ($has('when'))<td class="num nums whitespace-nowrap cell-dim {{ $late ? '!text-danger' : '' }}">{{ $t->shownLabel() }}</td>@endif
</tr>
