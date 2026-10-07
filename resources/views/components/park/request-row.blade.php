{{-- Строка таблицы заявок. Ячейка ТС в два этажа: название, под ним тип словом (красным при просрочке),
     «позвонить», госномер и номер убытка; от 640 тип и номер уходят в свои столбцы. Справа срок: день, под
     ним время. Пересказа «что делать» нет — его говорят тип и срок. Нажатие — карточка.
     Заявку завела почта и документы ещё читаются (`AutoScan::states`, `$arrive`) — строка силуэтом: вместо
     названия и номера полосы, тип словом виден сразу; пока читаем — полоса бежит по строке и «Читаем документы, 2 из 5».
     Дочитали — строка обычная, `arrive_controller` проявляет её. Откуда заявка (`x-park.request-source`: адрес и время
     письма, или кто завёл) — с первой секунды, и у силуэта: на ПК столбцом «Откуда», в краткой таблице третьим этажом. --}}
@props(['req', 'arrive' => null])
@php
    $v = $req->vehicle;
    $late = $req->isOverdue();
    $state = $arrive['state'] ?? null;
    $busy = in_array($state, ['ghost', 'reading'], true);
@endphp
<tr data-detail-key="{{ $req->id }}" id="request-{{ $req->id }}" @if ($arrive) data-controller="arrive" data-arrive-state-value="{{ $state }}" data-arrive-subject-value="v:{{ $v->id }}" @endif @class(['arrive' => $arrive, 'arrive--'.$state => $busy])>
    <td class="grow">
        @if ($busy)
            <x-ui.row-link :key="$req->id"><span class="cell-title"><span class="skeleton arrive-bar w-40 max-w-full"></span></span></x-ui.row-link>
            <span class="cell-sub">
                <span class="sm:hidden text-ink">{{ mb_strtolower($req->type->label()) }}</span>
                @if ($state === 'reading')
                    <span class="arrive-progress spark-busy"><x-ui.spark class="size-3.5"/>{{ $arrive['what'] === 'photos' ? 'Читаем фото' : 'Читаем документы' }}, {{ $arrive['i'] }} из {{ $arrive['n'] }}</span>
                @else
                    <span class="skeleton arrive-bar w-20"></span>
                @endif
            </span>
            <x-park.request-source :req="$req" class="cell-source"/>
        @else
            <x-ui.row-link :key="$req->id"><span class="cell-title"><span class="cell-name">{{ $v->titleWithYear() }}</span><x-ui.plate :value="$v->plate" class="title-plate"/></span></x-ui.row-link>
            <span class="cell-sub" data-controller="fitline">
                <span class="sm:hidden {{ $late ? 'text-danger' : 'text-ink' }}">{{ mb_strtolower($req->type->label()) }}</span>
                @if ($req->needsCall())<span class="text-urgent">позвонить</span>@endif
                <span class="fit-core"><x-park.ref :vehicle="$v" class="sm:hidden"/><x-ui.plate :value="$v->plate" class="sub-plate"/></span>
            </span>
            {{-- Третий этаж — только в краткой таблице (телефон, ПК с открытой карточкой); на ПК — свой столбец. --}}
            <x-park.request-source :req="$req" class="cell-source"/>
        @endif
    </td>
    <td class="cell-dim hidden sm:table-cell req-source-col"><x-park.request-source :req="$req"/></td>
    <td class="cell-dim hidden sm:table-cell">
        @if ($busy)
            <span class="skeleton arrive-bar w-28"></span>
        @else
            <span class="vendor-ref">@if ($v->vendor)<button type="button" class="vendor-tip" data-tip="{{ $v->vendor->name }}" aria-label="{{ $v->vendor->name }}"><x-vendor.logo :vendor="$v->vendor"/></button>@endif @if ($v->ref)<x-ui.copy-code :value="$v->ref"/>@endif</span>
        @endif
    </td>
    <td class="hidden sm:table-cell {{ $late ? 'text-danger' : '' }}">{{ $req->type->label() }}</td>
    <td class="num nums">
        @if ($req->planned_at)
            <span class="block sm:inline {{ $late ? 'text-danger' : '' }}">{{ $req->planned_at->translatedFormat('j M') }}</span>
            <span class="cell-sub sm:ml-1 sm:inline">{{ $req->planned_at->format('H:i') }}</span>
        @endif
    </td>
    <td class="cell-dim col-detail-hide hidden lg:table-cell">{{ $req->assignee?->name }}</td>
    <td class="cell-dim col-detail-hide hidden lg:table-cell">{{ ($req->yard ?? $v->yard)?->name }}</td>
</tr>
