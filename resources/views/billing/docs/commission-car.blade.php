<table>
    <tr><th>Марка/модель</th><td>{{ mb_strtoupper(trim(($v->brand?->name ?? '').' '.($v->model?->name ?? ''))) ?: '—' }}</td></tr>
    <tr><th>Государственный регистрационный знак</th><td>{{ $v->plate ?? '—' }}</td></tr>
    <tr><th>Цвет</th><td>{{ $v->color ? mb_strtoupper($v->color) : '—' }}</td></tr>
    <tr><th>Год выпуска</th><td>{{ $v->year ?? '—' }}</td></tr>
    <tr><th>Идентификационный номер (VIN)</th><td>{{ $v->vin ?? '—' }}</td></tr>
    <tr><th>Паспорт ТС</th><td>{{ $v->pts ?? '—' }}</td></tr>
    <tr><th>Свидетельство о регистрации ТС</th><td>{{ $v->sts ?? '—' }}</td></tr>
</table>
