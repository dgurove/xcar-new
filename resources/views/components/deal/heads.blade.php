{{-- Раздел «Сделки» сайта — большими заголовками в ряд: «Сделки | Гараж | Покупатели» (18.11.2026, владелец: «гараж в сделки
     переместил бы, это просто вторым заголовком, как у нас в оплатах в CRM три заголовка»; прежде пилюли «Сделки | Покупатели |
     Чаты» и «Гараж» своим табом). Три адреса одного раздела — `/deals`, `/garage`, `/buyers`: таб «Сделки» возвращает на тот,
     где был (память разделов). Заголовок — только открытое человеку; красное число — что ждёт его (`Nav::badges`, живое).
     Стоит на месте h1: шелл с `:heading="false"`. У кого «Сделок» в таб-баре нет (покупатель с гаражом — «Гараж» сам таб) или
     заголовок один, — обычный h1 `title`. `count` — серое число позиций у текущего (как было у h1 «Гараж 3»). --}}
@props(['current', 'title' => null, 'count' => null])
@php
    use App\Support\Nav;
    $user = auth()->user();
    $staff = $user->isAdmin() && ! $user->isManager();
    $inDeals = collect(Nav::tabs($user))->contains(fn ($t) => $t['href'] === '/deals');
    $heads = $inDeals ? array_filter([
        '/deals' => ['Сделки', $staff ? null : '/deals/asks'],
        '/garage' => $user->canGarage() ? ['Гараж', '/garage'] : null,
        '/buyers' => $user->isManager() ? ['Покупатели', '/buyers'] : null,
    ]) : [];
@endphp
@if (count($heads) > 1)
    <x-ui.big-tabs label="Сделки" :tabs="array_map(fn ($h) => $h[0], $heads)" :current="$current" :href="fn ($k) => $k"
        :badges="Nav::badges($user)" :live="array_filter(array_map(fn ($h) => $h[1], $heads))" {{ $attributes }}/>
@else
    <h1 {{ $attributes->class('head-title text-xl sm:text-2xl') }}>{{ $title ?? ($heads[$current][0] ?? 'Сделки') }}@if ($count !== null) <span class="nums ml-2 text-base font-normal text-ink-dim" data-shell-count>{{ $count }}</span>@endif</h1>
@endif
