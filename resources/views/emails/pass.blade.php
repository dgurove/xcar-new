{{-- Пропуск покупателю письмом: знак XCar, QR крупно (картинкой по Content-ID — почтовики SVG и data: не показывают),
     под ним код текстом и кнопка «Открыть пропуск» на случай, если почта спрятала картинки; дальше ТС, парковка с картой
     и дата. Вёрстка письма — таблицами и стилями в атрибутах: так её одинаково рисуют Gmail, Mail.ru, Яндекс и Outlook. --}}
@php
    $v = $pass->vehicle;
    $yard = $v->yard;
    $ink = '#1d1d1b'; $muted = '#808080'; $lime = '#97bf0d'; $line = '#ececec';
    $font = "font-family:Onest,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;";
    [$status, $tone] = $pass->status();
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>Пропуск на получение ТС</title>
</head>
<body style="margin:0;padding:0;background:#f2f2f2;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">QR-код для получения {{ $v->titleWithYear() }}. Покажите его сотруднику парковки</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f2f2;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:24px;">
        <tr><td style="padding:28px 28px 0 28px;">
            <img src="cid:{{ $logoCid }}" width="120" height="30" alt="XCar" style="display:block;border:0;width:120px;height:30px;">
        </td></tr>
        <tr><td style="padding:24px 28px 4px 28px;{{ $font }}font-size:26px;line-height:32px;font-weight:500;color:{{ $ink }};">Ваш пропуск на получение ТС</td></tr>
        <tr><td style="padding:0 28px;{{ $font }}font-size:15px;line-height:22px;color:{{ $muted }};">Покажите этот QR-код сотруднику парковки. Выдаём только по нему</td></tr>

        <tr><td align="center" style="padding:28px 28px 8px 28px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border:1px solid {{ $line }};border-radius:16px;">
                <tr><td style="padding:12px;"><img src="cid:{{ $qrCid }}" width="240" height="240" alt="QR-код пропуска {{ $pass->code }}" style="display:block;border:0;width:240px;height:240px;"></td></tr>
            </table>
        </td></tr>
        <tr><td align="center" style="padding:4px 28px 0 28px;{{ $font }}font-size:13px;line-height:18px;letter-spacing:1px;color:{{ $muted }};">{{ trim(chunk_split($pass->code, 5, ' ')) }}</td></tr>
        @if (! $pass->isConfirmed())
            <tr><td align="center" style="padding:12px 28px 0 28px;{{ $font }}font-size:13px;line-height:18px;color:#ff8800;">Код заработает, когда страховая подтвердит покупателя</td></tr>
        @endif
        <tr><td align="center" style="padding:20px 28px 8px 28px;">
            <a href="{{ $pageUrl }}" style="display:inline-block;background:{{ $lime }};color:#ffffff;{{ $font }}font-size:15px;line-height:20px;font-weight:500;text-decoration:none;padding:14px 28px;border-radius:999px;">Открыть пропуск</a>
        </td></tr>

        <tr><td style="padding:24px 28px 0 28px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid {{ $line }};">
                @foreach (array_filter([
                    ['ТС', $v->titleWithYear().($v->plate ? ', '.$v->plate : '')],
                    $yard ? ['Парковка', $yard->name] : null,
                    $yard ? ['Адрес', $yard->fullAddress(), $yard->mapUrl()] : null,
                    ['Когда заберёте', $pass->pickup_on->translatedFormat('j F, l')],
                    ['Получатель', $pass->name],
                ]) as $row)
                    <tr>
                        <td style="padding:12px 12px 12px 0;border-bottom:1px solid {{ $line }};{{ $font }}font-size:13px;line-height:18px;color:{{ $muted }};width:34%;vertical-align:top;">{{ $row[0] }}</td>
                        <td style="padding:12px 0;border-bottom:1px solid {{ $line }};{{ $font }}font-size:15px;line-height:20px;color:{{ $ink }};vertical-align:top;">
                            @if (isset($row[2]))<a href="{{ $row[2] }}" style="color:#669709;text-decoration:none;">{{ $row[1] }}</a>@else{{ $row[1] }}@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </td></tr>
        <tr><td style="padding:20px 28px 28px 28px;{{ $font }}font-size:13px;line-height:19px;color:{{ $muted }};">Возьмите с собой паспорт: сотрудник сверит его с данными анкеты. Дату можно поменять по ссылке «Открыть пропуск»</td></tr>
    </table>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
        <tr><td align="center" style="padding:16px 12px;{{ $font }}font-size:12px;line-height:18px;color:#a7a7a7;">Письмо отправлено автоматически, отвечать на него не нужно</td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
