{{-- Общий вид системных писем с no-reply (06.11.2026, `App\Mail\SystemMail`): знак XCar, над заголовком серым — о чём,
     заголовок, под ним серым — подробность, белая карточка (секция `card`), одна кнопка, мелкая приписка. Как у пропуска —
     таблицами и стилями в атрибутах: одинаково в Mail.ru, Gmail, Яндексе и Outlook. Логотип — `cid:logo` (SystemMail
     вкладывает его сам). Переменные: preheader, eyebrow, title, subtitle, button (['text', 'url']), note, unsubscribe, compact (заголовок 20 вместо 26 — длинные заголовки уведомлений). --}}
@php
    $ink = '#1d1d1b'; $muted = '#808080'; $lime = '#97bf0d';
    $font = "font-family:Onest,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;";
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f2f2;">
@if (! empty($preheader))<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f2f2;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;">
        <tr><td align="center" style="padding:0 0 20px 0;">
            <img src="cid:logo" width="112" height="28" alt="XCar" style="display:block;border:0;width:112px;height:28px;">
        </td></tr>
        @if (! empty($eyebrow))<tr><td style="padding:0 4px;{{ $font }}font-size:13px;line-height:18px;color:{{ $muted }};">{{ $eyebrow }}</td></tr>@endif
        <tr><td style="padding:4px 4px 0 4px;{{ $font }}{{ ! empty($compact) ? 'font-size:20px;line-height:26px;' : 'font-size:26px;line-height:32px;' }}font-weight:500;color:{{ $ink }};">{{ $title }}</td></tr>
        @if (! empty($subtitle))<tr><td style="padding:4px 4px 0 4px;{{ $font }}font-size:15px;line-height:20px;color:{{ $muted }};">{{ $subtitle }}</td></tr>@endif
        @hasSection('card')
            <tr><td style="padding:16px 0 0 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:24px;">
                    @yield('card')
                </table>
            </td></tr>
        @endif
        @if (! empty($button))
            <tr><td align="center" style="padding:20px 0 0 0;">
                <a href="{{ $button['url'] }}" style="display:inline-block;background:{{ $lime }};color:#ffffff;{{ $font }}font-size:15px;line-height:20px;font-weight:500;text-decoration:none;padding:14px 32px;border-radius:24px;">{{ $button['text'] }}</a>
            </td></tr>
        @endif
        @if (! empty($note))<tr><td style="padding:16px 4px 0 4px;{{ $font }}font-size:13px;line-height:19px;color:{{ $muted }};">{{ $note }}</td></tr>@endif
        <tr><td align="center" style="padding:24px 12px 0 12px;{{ $font }}font-size:12px;line-height:18px;color:#a7a7a7;">
            Письмо отправлено автоматически, отвечать на него не нужно@if (! empty($unsubscribe))<br><a href="{{ $unsubscribe }}" style="color:#a7a7a7;">Не присылать на почту</a>@endif
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
