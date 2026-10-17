{{-- Строка «Вывоза» (18.10.2026, Катя и Лиза: «не хватает номера убытка, логотипа страховой, менеджера — кому везти»).
     Столбцы — `cols` (`TransportController::columns`, шапка — `x-logistics.head`; пусто — все, что видно человеку). Ведущим
     логистику — где продажа (`SaleState`): ищем покупателя — строка приглушена, три дня без покупателя — красная точка и
     дни красным, покупатель есть — словом цветом и менеджер чипом «кому везти». Второй этаж названия (телефон, карточка
     рядом — краткая таблица показывает только `sm:hidden`): номер убытка с логотипом, продажа или что дальше, «→ Иван П.»,
     кто вывозит. checkable — галочка пачки на «Без исполнителя». --}}
@props(['t', 'checkable' => false, 'cols' => []])
@php
    $me = auth()->user();
    $lead = $me->leadsLogistics();
    $staff = $me->isStaff();
    $has = fn (string $k) => in_array($k, $cols, true);
    $sale = $lead ? \App\Logistics\SaleState::of($t) : null;
    $deciding = $t->state === \App\Logistics\TransportState::New;
    // Себя исполнитель в строке не видит; у логиста без исполнителя — «у логиста».
    $who = $t->isExecutedBy($me) ? null : $t->executorUser;
    $logist = ! $t->executorUser && $t->coordinator && $t->coordinator_id !== $me->id ? $t->coordinator : null;
    $late = $t->isLate();
    $manager = $sale?->manager;
    $to = $manager ? null : ($t->destination || ! $t->forSale() ? $t->destinationLabel() ?? $t->toYard?->name : null);
    $ref = $t->car?->claim_ref;
    $vendorId = $t->vendorId();
@endphp
<tr data-detail-key="{{ $t->id }}" id="transport-{{ $t->id }}" data-search-row @class(['is-dim' => $sale?->waiting])>
    @if ($checkable)<td class="pick-cell"><label class="row-check" aria-label="Выбрать"><span class="check"><input type="checkbox" id="pick-transport-{{ $t->id }}" data-turbo-permanent name="transports[]" value="{{ $t->id }}" data-pick-target="box" data-action="pick#sync"></span></label></td>@endif
    <td class="grow">
        <x-ui.row-link :key="$t->id"><span class="cell-title"><x-ui.cat-icon :category="$t->category()"/><span class="cell-name">@if ($sale?->urgent)<span class="card-tone card-tone--danger" aria-label="Срочно"></span>@endif{{ $t->title() }}</span><x-car.keys :count="$t->car?->keys_count"/></span></x-ui.row-link>
        <span class="cell-sub">
            @if ($staff && ($vendorId || $ref))<span class="sm:hidden"><x-vendor.ref :vendor-id="$vendorId" :ref="$ref"/></span>@endif
            @if ($sale && $deciding)<span class="sm:hidden whitespace-nowrap {{ $sale->tone }}">{{ $sale->word }}</span>
            @elseif ($has('step'))<span class="sm:hidden whitespace-nowrap {{ $late ? 'text-danger' : $t->state->tone() }}">{{ $late ? 'не вывезли в срок' : $t->next() }}</span>@endif
            @if ($manager)<span class="sm:hidden whitespace-nowrap">→ {{ $manager->shortName() }}</span>@elseif ($to)<span class="sm:hidden">→ {{ $to }}</span>@endif
            @if ($has('who') && ($who || $logist))<span class="sm:hidden">{{ $who ? $who->shortName() : 'у логиста '.$logist->shortName() }}</span>@endif
        </span>
    </td>
    @if ($has('vendor'))<td class="hidden sm:table-cell"><x-vendor.ref :vendor-id="$vendorId" :ref="$ref" cell/></td>@endif
    @if ($has('sale'))<td class="hidden sm:table-cell"><span class="{{ $sale?->tone }}">{{ $sale?->word }}</span></td>@endif
    @if ($has('to'))<td class="hidden sm:table-cell">@if ($manager)<x-ui.person :user="$manager"/>@elseif ($to)<span class="text-ink-muted">{{ $to }}</span>@endif</td>@endif
    @if ($has('city'))<td class="col-detail-hide hidden 2xl:table-cell">@if ($city = $t->city())<x-ui.place class="whitespace-nowrap text-ink-muted">{{ $city }}</x-ui.place>@endif</td>@endif
    @if ($has('step'))<td class="hidden sm:table-cell"><span class="{{ $late ? 'text-danger' : $t->state->tone() }}">{{ $late ? 'не вывезли в срок' : $t->next() }}</span></td>@endif
    @if ($has('who'))<td class="hidden sm:table-cell">@if ($who)<x-ui.person :user="$who"/>@elseif ($logist)<span class="text-ink-muted">у логиста {{ $logist->shortName() }}</span>@endif</td>@endif
    @if ($has('created'))<td @class(['num nums whitespace-nowrap cell-dim', 'col-detail-hide hidden 2xl:table-cell' => $has('when')])>{{ \App\Support\Ago::stamp($t->created_at, time: true) }}</td>@endif
    @if ($has('when'))<td class="num nums whitespace-nowrap cell-dim {{ $late ? '!text-danger' : '' }}">{{ $t->shownLabel() }}</td>@endif
</tr>
