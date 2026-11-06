{{-- Номера «К запросу» сотруднику (`MailClaimDigest`, 06.11.2026, владелец: «воду не лить, чтобы номера удобно копировать
     и красиво»): вендор заголовком, сколько и когда — под ним, номера столбиком в одной карточке, шрифтом письма (моноширинный Courier в Mail.ru рвал «AUT - 26 - …», 06.11.2026). Выделил
     карточку — скопировались ровно номера по строке; текстовая часть письма — тоже только они. --}}
@extends('emails.layout')
@section('card')
    @php $font = "font-family:Onest,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;"; @endphp
    <tr><td style="padding:12px 20px;">
        @foreach ($refs as $i => $ref)
            <div style="{{ $font }}font-size:18px;line-height:24px;font-weight:500;letter-spacing:.3px;color:#1d1d1b;padding:12px 0;{{ $i ? 'border-top:1px solid #ececec;' : '' }}">{{ $ref }}</div>
        @endforeach
    </td></tr>
@endsection
