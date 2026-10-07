{{-- Откуда заявка — в строке «Заявок» всегда, и у силуэта новой (владелец 07.10.2026: «чтобы было понятно откуда это
     появилось»): из письма — адрес того, кто написал (не имя, копируется) и время письма; заведена руками — кто и когда;
     системой — только когда (`Request::source`). Время всегда с днём: «сегодня, 12:40», «вчера, 9:05», «7 окт, 12:40». --}}
@props(['req'])
@php
    $s = $req->source();
    $at = $s['at'];
    $day = $at?->isToday() ? 'сегодня' : ($at?->isYesterday() ? 'вчера' : $at?->translatedFormat($at->isCurrentYear() ? 'j M' : 'j M Y'));
@endphp
{{-- Время первым: по нему столбец ровный, адреса разной длины не двигают его. --}}
<span {{ $attributes->class('req-source') }}>
    @if ($at)<span class="req-source-when nums">{{ $day }}, {{ $at->format('G:i') }}</span>@endif
    @if ($s['email'])<x-mail.address :email="$s['email']" class="req-source-who"/>@elseif ($s['name'])<span class="req-source-who">{{ $s['name'] }}</span>@endif
</span>
