{{-- Реквизиты и подписи под ДКП ПРАЙМ и актом (14.11.2026, шаблон владельца): «ПРОДАВЕЦ: / ПОКУПАТЕЛЬ:», в акте «ТС СДАЛ
     (ПРОДАВЕЦ): / ТС ПРИНЯЛ (ПОКУПАТЕЛЬ):» без реквизитов. Покупатель-физлицо — паспорт, кем и когда выдан, место рождения,
     адрес; организация или ИП — реквизиты строками. Подпись «_____/Кузнецов А.В./» (`DkpDocument::signShort`) — в той же
     строке таблицы, что и реквизиты: одну строку dompdf не делит, и подпись не уезжает на чистый лист одна. --}}
@php
    $act ??= false;
    $s = $doc->seller; $b = $doc->buyer; $pp = $doc->passport($b);
    $sLines = $s ? array_values(array_filter([
        $s->legal_address ? 'Адрес: '.$s->legal_address : null,
        $s->ogrn ? 'ОГРН '.$s->ogrn : null,
        $s->inn ? ($s->kpp ? 'ИНН/КПП '.$s->inn.'/'.$s->kpp : 'ИНН '.$s->inn) : null,
        $s->account ? 'р/с '.$s->account.($s->bank_name ? ' в '.$s->bank_name : '') : null,
        $s->corr_account ? 'к/с '.$s->corr_account : null,
        $s->bik ? 'БИК '.$s->bik : null,
    ])) : [];
    $bLines = $doc->isCompany($b) ? $doc->requisites($b) : [];
@endphp
<table class="parties">
    <tr>
        <td>
            <b>{{ $act ? 'ТС СДАЛ (ПРОДАВЕЦ):' : 'ПРОДАВЕЦ:' }}</b>
            <div>{{ $s?->name ?: $s?->title() }}</div>
            @unless ($act)
                @foreach ($sLines as $line)<div>{{ $line }}</div>@endforeach
            @endunless
            <div class="sign"><span class="blank"></span>/{{ $doc->signShort($s) }}/</div>
            <div>М.П.</div>
        </td>
        <td>
            <b>{{ $act ? 'ТС ПРИНЯЛ (ПОКУПАТЕЛЬ):' : 'ПОКУПАТЕЛЬ:' }}</b>
            @if ($doc->isCompany($b))
                <div>{{ $b->full_name ?: $b->title() }}</div>
                @unless ($act)
                    @foreach ($bLines as $line)<div>{{ $line }}</div>@endforeach
                @endunless
            @else
                <div>{{ $doc->v($b?->name, 70) }}</div>
                @unless ($act)
                    <div>Паспорт: серия {{ $doc->v($pp['series'], 12) }} № {{ $doc->v($pp['number'], 20) }}, Выдан: {{ $doc->v($pp['issued_by'], 60) }}, {{ $doc->v($pp['issued_at'], 25) }} г.</div>
                    <div>Место рождения: {{ $doc->v($b?->birth_place, 55) }},</div>
                    <div>Адрес: {{ $doc->v($pp['address'], 65) }}</div>
                @endunless
            @endif
            <div class="sign"><span class="blank"></span>/{{ $doc->signShort($b) }}@if (! $doc->signShort($b))<span class="blank" style="width:30mm"></span>@endif/</div>
            @if ($doc->isCompany($b))<div>М.П.</div>@endif
        </td>
    </tr>
</table>
