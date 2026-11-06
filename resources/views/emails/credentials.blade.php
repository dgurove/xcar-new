{{-- Данные входа письмом (`PeopleMailController::credentials`): строки «Телефон / Почта» и «Пароль» в карточке, «Войти».
     Один шрифт и кегль у подписи и значения (06.11.2026: моноширинный рядом с обычным «прыгал»), подпись серым. --}}
@extends('emails.layout')
@section('card')
    @php $font = "font-family:Onest,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:16px;line-height:22px;"; @endphp
    @foreach ($rows as $i => [$label, $value])
        <tr>
            <td style="padding:14px 8px 14px 20px;{{ $i ? 'border-top:1px solid #ececec;' : '' }}{{ $font }}color:#808080;width:30%;white-space:nowrap;">{{ $label }}</td>
            <td align="right" style="padding:14px 20px 14px 8px;{{ $i ? 'border-top:1px solid #ececec;' : '' }}{{ $font }}color:#1d1d1b;font-weight:500;{{ $label === 'Пароль' ? 'letter-spacing:1px;' : '' }}">{{ $value }}</td>
        </tr>
    @endforeach
@endsection
