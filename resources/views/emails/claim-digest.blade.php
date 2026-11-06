{{-- Номера «К запросу» сотруднику (`MailClaimDigest`, 06.11.2026, владелец: «воду не лить, чтобы номера удобно копировать
     и красиво»): вендор заголовком, сколько и когда — под ним, номера столбиком в одной карточке, моноширинным. Выделил
     карточку — скопировались ровно номера по строке; текстовая часть письма — тоже только они. --}}
@extends('emails.layout')
@section('card')
    @php $mono = "font-family:'SF Mono',SFMono-Regular,Menlo,Consolas,'Roboto Mono','Courier New',monospace;"; @endphp
    <tr><td style="padding:12px 20px;">
        @foreach ($refs as $i => $ref)
            <div style="{{ $mono }}font-size:18px;line-height:24px;color:#1d1d1b;padding:10px 0;{{ $i ? 'border-top:1px solid #ececec;' : '' }}">{{ $ref }}</div>
        @endforeach
    </td></tr>
@endsection
