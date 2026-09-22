{{-- Акт сверки: шапка как у счёта, таблица «Дата, Документ, Дебет, Кредит», сальдо. Положительное сальдо — должны нам. --}}
@php use App\Support\Money; $fonts = resource_path('fonts/pdf'); @endphp
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Акт сверки {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}</title>
    <style>
        @if ($pdf ?? false)
        @font-face { font-family: "Onest"; font-weight: 400; src: url("file://{{ $fonts }}/Onest-Regular.ttf"); }
        @font-face { font-family: "Onest"; font-weight: 600; src: url("file://{{ $fonts }}/Onest-SemiBold.ttf"); }
        @endif
        body { font-family: "Onest", -apple-system, sans-serif; font-size: 11px; color: #111; margin: 0; padding: 28px 32px; }
        h1 { font-size: 16px; font-weight: 600; margin: 0 0 6px; }
        p { margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th { text-align: left; font-weight: 600; border-bottom: 1px solid #111; padding: 6px; }
        td { border-bottom: 1px solid #ddd; padding: 5px 6px; vertical-align: top; }
        .n { text-align: right; white-space: nowrap; }
        .sum td { border-bottom: none; font-weight: 600; padding-top: 8px; }
        .sign { margin-top: 40px; }
        .sign td { border: none; width: 50%; padding: 6px 0; }
        .line { border-bottom: 1px solid #111; height: 22px; width: 200px; display: inline-block; }
    </style>
</head>
<body>
    <h1>Акт сверки взаимных расчётов за период {{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }}</h1>
    <p>между <b>{{ $self->name }}</b>{{ $self->details() ? ' ('.$self->details().')' : '' }} и <b>{{ $party->name }}</b>{{ $party->details() ? ' ('.$party->details().')' : '' }}</p>
    <table>
        <thead><tr><th style="width:80px">Дата</th><th>Документ</th><th class="n" style="width:110px">Дебет, ₽</th><th class="n" style="width:110px">Кредит, ₽</th></tr></thead>
        <tbody>
            <tr><td></td><td>Сальдо на {{ $from->format('d.m.Y') }}</td><td class="n">{{ $opening > 0 ? Money::nums($opening, 2) : '' }}</td><td class="n">{{ $opening < 0 ? Money::nums(-$opening, 2) : '' }}</td></tr>
            @foreach ($rows as $r)
                <tr><td>{{ $r['at']->format('d.m.Y') }}</td><td>{{ $r['title'] }}{{ $r['offer'] ? ', '.$r['offer'] : '' }}</td><td class="n">{{ $r['debit'] > 0 ? Money::nums($r['debit'], 2) : '' }}</td><td class="n">{{ $r['credit'] > 0 ? Money::nums($r['credit'], 2) : '' }}</td></tr>
            @endforeach
            <tr class="sum"><td></td><td>Обороты за период</td><td class="n">{{ Money::nums(array_sum(array_column($rows, 'debit')), 2) }}</td><td class="n">{{ Money::nums(array_sum(array_column($rows, 'credit')), 2) }}</td></tr>
            <tr class="sum"><td></td><td>Сальдо на {{ $to->format('d.m.Y') }}</td><td class="n">{{ $closing > 0 ? Money::nums($closing, 2) : '' }}</td><td class="n">{{ $closing < 0 ? Money::nums(-$closing, 2) : '' }}</td></tr>
        </tbody>
    </table>
    <p style="margin-top:12px">{{ $closing > 0 ? 'На '.$to->format('d.m.Y').' задолженность '.$party->name.' перед '.$self->name.' — '.Money::nums($closing, 2).' ₽' : ($closing < 0 ? 'На '.$to->format('d.m.Y').' задолженность '.$self->name.' перед '.$party->name.' — '.Money::nums(-$closing, 2).' ₽' : 'На '.$to->format('d.m.Y').' задолженности нет') }}</p>
    <table class="sign">
        <tr><td>{{ $self->name }} <span class="line"></span></td><td>{{ $party->name }} <span class="line"></span></td></tr>
    </table>
</body>
</html>
