{{-- Заголовки раздела «Работа»: Сделки, Гараж, Вывоз, Почта, Чаты, Оплаты. Активный — h1 со
     своим числом, у остальных — счётчик из Nav::badges (горящие, непрочитанные). --}}
@props(['current', 'count' => null])
@php
    $badges = \App\Support\Nav::badges(auth()->user());
    // Модератору из «Работы» открыта только почта — остальные заголовки вели бы в 404.
    $titles = auth()->user()->canManageCrm() ? ['deals' => 'Сделки', 'garage' => 'Гараж', 'pickups' => 'Вывоз', 'mail' => 'Почта', 'chats' => 'Чаты', 'money' => 'Оплаты'] : ['mail' => 'Почта'];
@endphp
<div class="flex flex-wrap items-baseline gap-x-6 gap-y-2">
    @foreach ($titles as $key => $label)
        @if ($key === $current)
            <x-ui.section-title level="h1" :count="$count">{{ $label }}</x-ui.section-title>
        @else
            <x-ui.section-title :href="'/work/'.$key" :current="false" :count="$badges['/work/'.$key] ?? null">{{ $label }}</x-ui.section-title>
        @endif
    @endforeach
</div>
