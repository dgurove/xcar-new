{{-- Данные входа письмом (`PeopleMailController::credentials`): строки «Телефон / Почта» и «Пароль» в карточке, «Войти». --}}
@extends('emails.layout')
@section('card')
    @foreach ($rows as $i => [$label, $value])
        <tr>
            <td style="padding:14px 8px 14px 20px;{{ $i ? 'border-top:1px solid #ececec;' : '' }}font-family:Onest,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;font-size:15px;line-height:20px;color:#808080;width:35%;">{{ $label }}</td>
            <td style="padding:14px 20px 14px 8px;{{ $i ? 'border-top:1px solid #ececec;' : '' }}font-family:'SF Mono',SFMono-Regular,Menlo,Consolas,'Roboto Mono','Courier New',monospace;font-size:17px;line-height:22px;color:#1d1d1b;">{{ $value }}</td>
        </tr>
    @endforeach
@endsection
