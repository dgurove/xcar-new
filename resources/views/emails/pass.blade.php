{{-- Пропуск покупателю письмом — тот же вид, что страница пропуска: знак, ТС заголовком, плашка с полосой состояния
     и QR (картинкой по Content-ID — почтовики SVG и data: не показывают), код, кнопка «Открыть пропуск» на случай,
     если почта спрятала картинки; ниже строками когда, где (с «Маршрут») и кто. Вёрстка — таблицами и стилями в
     атрибутах: так её одинаково рисуют Gmail, Mail.ru, Яндекс и Outlook. --}}
@php
    $v = $pass->vehicle;
    $yard = $v->yard;
    $ink = '#1d1d1b'; $muted = '#808080'; $lime = '#97bf0d'; $limeText = '#669709'; $line = '#ececec';
    $font = "font-family:Onest,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;";
    [$band, $bandBg, $bandInk] = $pass->isConfirmed()
        ? ['Подтверждено', '#f0f7d8', $limeText]
        : ['Код заработает, когда страховая подтвердит покупателя', '#fff3e0', '#e07800'];
    $rows = array_filter([
        ['Когда', \Illuminate\Support\Str::ucfirst($pass->pickup_on->translatedFormat('l, j F'))],
        $yard ? ['Где', $yard->name, $yard->fullAddress(), $yard->mapUrl()] : null,
        ['Получатель', $pass->name],
    ]);
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Пропуск на получение</title>
</head>
<body style="margin:0;padding:0;background:#f2f2f2;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">QR-код для получения {{ $v->titleWithYear() }}. Покажите его сотруднику парковки</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f2f2;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;">
        <tr><td align="center" style="padding:0 0 20px 0;">
            <img src="cid:{{ $logoCid }}" width="112" height="28" alt="XCar" style="display:block;border:0;width:112px;height:28px;">
        </td></tr>
        <tr><td style="padding:0 4px;{{ $font }}font-size:13px;line-height:18px;color:{{ $muted }};">Пропуск на получение</td></tr>
        <tr><td style="padding:4px 4px 0 4px;{{ $font }}font-size:26px;line-height:32px;font-weight:500;color:{{ $ink }};">{{ $v->titleWithYear() }}</td></tr>
        @if ($v->plate)<tr><td style="padding:4px 4px 0 4px;{{ $font }}font-size:15px;line-height:20px;color:{{ $muted }};">{{ $v->plate }}</td></tr>@endif

        <tr><td style="padding:16px 0 0 0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:24px;overflow:hidden;">
                <tr><td style="background:{{ $bandBg }};padding:12px 16px;{{ $font }}font-size:15px;line-height:20px;font-weight:500;color:{{ $bandInk }};">{{ $band }}</td></tr>
                <tr><td align="center" style="padding:20px 16px 4px 16px;">
                    <img src="cid:{{ $qrCid }}" width="256" height="256" alt="QR-код пропуска {{ $pass->code }}" style="display:block;border:0;width:256px;height:256px;">
                </td></tr>
                <tr><td align="center" style="padding:4px 16px 0 16px;{{ $font }}font-size:13px;line-height:18px;letter-spacing:3px;color:{{ $muted }};">{{ trim(chunk_split($pass->code, 5, ' ')) }}</td></tr>
                <tr><td align="center" style="padding:20px 16px 24px 16px;">
                    <a href="{{ $pageUrl }}" style="display:inline-block;background:{{ $lime }};color:#ffffff;{{ $font }}font-size:15px;line-height:20px;font-weight:500;text-decoration:none;padding:14px 32px;border-radius:24px;">Открыть пропуск</a>
                </td></tr>
            </table>
        </td></tr>

        <tr><td style="padding:12px 0 0 0;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:16px;">
                @foreach ($rows as $i => $row)
                    <tr>
                        <td style="padding:14px 8px 14px 16px;{{ $i ? "border-top:1px solid $line;" : '' }}{{ $font }}font-size:15px;line-height:20px;color:{{ $muted }};width:30%;vertical-align:top;">{{ $row[0] }}</td>
                        <td style="padding:14px 16px 14px 8px;{{ $i ? "border-top:1px solid $line;" : '' }}{{ $font }}font-size:15px;line-height:20px;color:{{ $ink }};vertical-align:top;">
                            {{ $row[1] }}
                            @isset($row[2])<br><span style="font-size:13px;line-height:18px;color:{{ $muted }};">{{ $row[2] }}</span><br><a href="{{ $row[3] }}" style="font-size:15px;line-height:24px;color:{{ $limeText }};text-decoration:none;">Маршрут ›</a>@endisset
                        </td>
                    </tr>
                @endforeach
            </table>
        </td></tr>
        <tr><td style="padding:16px 4px 0 4px;{{ $font }}font-size:13px;line-height:19px;color:{{ $muted }};">Возьмите с собой паспорт: сотрудник сверит его с данными анкеты. Дату можно поменять в пропуске</td></tr>
        <tr><td align="center" style="padding:24px 12px 0 12px;{{ $font }}font-size:12px;line-height:18px;color:#a7a7a7;">Письмо отправлено автоматически, отвечать на него не нужно</td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
