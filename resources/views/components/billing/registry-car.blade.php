{{-- ТС документа строкой (`ClosingDoc::composition`): название и убыток, под ними период и сутки (у эвакуации — что за
     работа), справа сумма; нажатие — дело ТС. --}}
@props(['car'])
@php use App\Support\Money; $v = $car['vehicle']; @endphp
<a href="/cars/{{ $v->id }}" class="row" data-turbo-frame="_top">
    <span class="min-w-0 flex-1">
        <span class="block truncate">{{ $v->titleWithYear() }}</span>
        <span class="row-sub">
            @if ($v->ref)<span>{{ $v->ref }}</span>@endif
            @if ($car['from'])<span class="nums whitespace-nowrap">{{ $car['from']->format('d.m') }} – {{ $car['to']->format('d.m') }}, {{ $car['days'] }}&nbsp;сут</span>@endif
            @foreach ($car['extra'] as $t)<span>{{ $t }}</span>@endforeach
        </span>
    </span>
    <span class="nums shrink-0">{{ Money::rub($car['amount']) }}</span>
</a>
