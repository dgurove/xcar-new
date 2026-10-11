{{-- Заголовки раздела «Работа»: Сделки, Гараж, Вывоз, Почта, Чаты, Оплаты. Число у заголовка одно и то же, открыт он или
     нет, — счётчик из Nav::badges (горящие, непрочитанные; таб «Работа» с 11.10.2026 — только непрочитанные чаты); 10.10.2026 владелец: «на табе 26, а
     внутри 42». Своё число (`count`) — только у раздела без бейджа (Гараж). На телефоне — одной строкой, лента
     листается вбок от края до края, текущий в кадре (`activePillIntoView`); 09.10.2026 владелец: в две строки шапка
     «Оплат» занимала полэкрана. --}}
{{-- edge — лента до правого края экрана; рядом справа кнопка (почта: «Написать») — обрывается у неё. --}}
@props(['current', 'count' => null, 'edge' => true])
@php
    $badges = \App\Support\Nav::badges(auth()->user());
    // Модератору из «Работы» открыта только почта — остальные заголовки вели бы в 404.
    $titles = auth()->user()->canManageCrm() ? ['deals' => 'Сделки', 'garage' => 'Гараж', 'mail' => 'Почта', 'chats' => 'Чаты', 'money' => 'Оплаты'] : ['mail' => 'Почта'];
@endphp
<div class="work-titles flex items-baseline gap-x-6 gap-y-2 sm:flex-wrap {{ $edge ? 'max-sm:-mx-4 max-sm:px-4' : 'max-sm:-ml-4 max-sm:pl-4' }} max-sm:overflow-x-auto max-sm:whitespace-nowrap [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
    @foreach ($titles as $key => $label)
        @if ($key === $current)
            <x-ui.section-title level="h1" :count="array_key_exists('/work/'.$key, $badges) ? $badges['/work/'.$key] : $count" aria-current="page" class="shrink-0">{{ $label }}</x-ui.section-title>
        @else
            <x-ui.section-title :href="'/work/'.$key" :current="false" :count="$badges['/work/'.$key] ?? null" class="shrink-0">{{ $label }}</x-ui.section-title>
        @endif
    @endforeach
</div>
