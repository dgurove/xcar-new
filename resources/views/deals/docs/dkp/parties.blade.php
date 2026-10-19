{{-- Реквизиты и подписи сторон под ДКП юрлица и под актом: Продавец | Покупатель. Строками — шапка, данные, подпись:
     одну строку таблицы dompdf не делит и уносил весь блок на чистый лист. В акте ($act) банка нет: акту он не нужен. --}}
@php
    $_ = fn ($v, $n = 24) => filled($v) ? e($v) : str_repeat('_', $n);
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
                <div>{!! $_($b?->name, 36) !!}</div>
                <div>{!! $_($pp['birth'], 12) !!} г.р.</div>
                <div>Паспорт: серия {!! $_($pp['series'], 6) !!} № {!! $_($pp['number'], 8) !!}</div>
                <div>Выдан: {!! $_($pp['issued_by'], 30) !!}</div>
                <div>Дата выдачи: {!! $_($pp['issued_at'], 12) !!} г.@if ($pp['code']), код подразделения {{ $pp['code'] }}@endif</div>
                <div>Адрес регистрации: {!! $_($pp['address'], 30) !!}</div>
            @endif
        </td>
    </tr>
    <tr>
        <td>
            <div class="sign">______________________________</div>
            <div>{!! $_($doc->signer($s)) !!}</div>
            <div class="mp">м.п.</div>
        </td>
        <td>
            <div class="sign">______________________________</div>
            <div>{!! $_($doc->signer($b)) !!}</div>
            @if ($doc->isCompany($b))<div class="mp">м.п.</div>@endif
        </td>
    </tr>
</table>
