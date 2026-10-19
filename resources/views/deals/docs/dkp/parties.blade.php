{{-- Реквизиты и подписи сторон под ДКП юрлица и под актом: Продавец | Покупатель. Строками — шапка, данные, подпись:
     одну строку таблицы dompdf не делит и уносил весь блок на чистый лист. В акте ($act) банка нет: акту он не нужен.
     Подпись по ГОСТ: линия и расшифровка «А. В. Кузнецов». --}}
@php
    $act ??= false;
    $s = $doc->seller; $b = $doc->buyer; $pp = $doc->passport($b);
    $lines = fn ($p) => array_values(array_filter($doc->requisites($p), fn ($l) => ! $act || ! preg_match('/^(р\/с|к\/с|БИК)/u', $l)));
@endphp
<table class="parties">
    <tr><td><b>Продавец</b></td><td><b>Покупатель</b></td></tr>
    <tr>
        <td>
            @if ($s)
                <div>{{ $s->full_name ?: $s->name }}</div>
                @foreach ($lines($s) as $line)<div>{{ $line }}</div>@endforeach
            @endif
        </td>
        <td>
            @if ($doc->isCompany($b))
                <div>{{ $b->full_name ?: $b->name }}</div>
                @foreach ($lines($b) as $line)<div>{{ $line }}</div>@endforeach
            @else
                <div>{{ $doc->v($b?->name, 70) }}</div>
                <div>Дата рождения: {{ $doc->v($pp['birth'], 25) }}</div>
                <div>Паспорт: серия {{ $doc->v($pp['series'], 12) }} № {{ $doc->v($pp['number'], 20) }}</div>
                <div>Выдан: {{ $doc->v($pp['issued_by'], 60) }}</div>
                <div>Дата выдачи: {{ $doc->v($pp['issued_at'], 25) }}@if ($pp['code']), код подразделения {{ $pp['code'] }}@endif</div>
                <div>Адрес регистрации: {{ $doc->v($pp['address'], 45) }}</div>
            @endif
        </td>
    </tr>
    <tr>
        <td>
            <div class="sign"><span class="blank"></span> {{ $doc->signature($s) }}</div>
            <div>М. П.</div>
        </td>
        <td>
            <div class="sign"><span class="blank"></span> {{ $doc->signature($b) }}</div>
            @if ($doc->isCompany($b))<div>М. П.</div>@endif
        </td>
    </tr>
</table>
