{{-- Ответ сотрудника на «Написать нам» гостю (`Chats\Jobs\SendGuestReply`): имя, наш ответ его же переносами, кнопка
     «Продолжить переписку» — подписанная ссылка `contacts.open`, она возвращает обращение в браузер, откуда её открыли.
     Вёрстка таблицами и стилями в атрибутах, как у пропуска (`emails/pass`). --}}
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
<title>Ответ XCar</title>
</head>
<body style="margin:0;padding:0;background:#f2f2f2;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f2f2;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:480px;background:#ffffff;border-radius:24px;">
        <tr><td style="padding:24px 24px 0 24px;{{ $font }}font-size:13px;line-height:18px;color:{{ $muted }};">XCar, ответ на ваше обращение</td></tr>
        @if ($name)<tr><td style="padding:8px 24px 0 24px;{{ $font }}font-size:15px;line-height:22px;color:{{ $ink }};">{{ $name }}, здравствуйте</td></tr>@endif
        <tr><td style="padding:12px 24px 0 24px;{{ $font }}font-size:15px;line-height:22px;color:{{ $ink }};">{!! nl2br(e($text)) !!}</td></tr>
        <tr><td style="padding:24px 24px 24px 24px;">
            <a href="{{ $url }}" style="display:inline-block;padding:12px 24px;border-radius:999px;background:{{ $lime }};color:#ffffff;text-decoration:none;{{ $font }}font-size:15px;line-height:20px;font-weight:500;">Продолжить переписку</a>
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
