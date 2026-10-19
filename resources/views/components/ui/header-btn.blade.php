{{-- Квадратная кнопка шапки, одна на оба ряда: избранное, чаты, уведомления со шторкой, тревога, тема. Телефон — квадрат
     header-sq со значком 18 px, ПК (wide) — капсула ряда header-h со значком 16 px и своими id шторки. --}}
@props(['name', 'wide' => false, 'badges' => []])
@php
    $class = fn (bool $relative) => ($wide ? 'header-btn header-btn-square header-h' : 'header-btn header-sq').($relative ? ' relative' : '').($wide ? ' w-[1.875rem]' : '');
    $size = $wide ? 'size-4' : 'size-[18px]';
    $tail = $wide ? '-wide' : '';
    $link = ['favorites' => ['/account/favorites', 'bookmark', 'Избранное'], 'chats' => ['/work/chats', 'chat', 'Чаты']][$name] ?? null;
@endphp
@if ($link)
    <a href="{{ $link[0] }}" class="{{ $class(true) }}" aria-label="{{ $link[2] }}" @if ($name === 'chats' && ! $wide) data-chats-phone @endif>
        <x-ui.icon :name="$link[1]" class="{{ $size }}"/><x-ui.badge :href="$link[0]" :badges="$badges"/>
    </a>
@elseif ($name === 'notifications')
    <div class="contents header-sq-wrap" data-controller="sheet notifications">
        <button type="button" class="{{ $class(true) }}" data-action="sheet#open notifications#refresh" aria-label="Уведомления">
            <x-ui.icon name="bell" class="{{ $size }}"/><x-ui.badge href="/account/notifications" :badges="$badges"/>
        </button>
        <x-ui.sheet :id="'notifications-sheet'.$tail" title="Уведомления">
            <turbo-frame id="notifications-latest{{ $tail }}" src="/account/notifications/latest" loading="lazy" data-notifications-target="frame" class="block min-h-32">@unless ($wide)<x-ui.skeleton :rows="3"/>@endunless</turbo-frame>
            <div class="mt-4 flex gap-2">
                <a href="/account/notifications" class="btn btn-s btn-quiet flex-1">Все уведомления</a>
            </div>
        </x-ui.sheet>
    </div>
@elseif ($name === 'alarm')
    {{-- Тревога «нет покупателя» (19.10.2026, `Offers\Binding`): красная мерцающая капсула с числом, пока причина не устранена;
         закрыть её нельзя — гаснет сама. Нажатие — экран «Горит» (`/alarms`). Число и видимость обновляет `/live/badges`. --}}
    <x-alarm.button :wide="$wide"/>
@elseif ($name === 'theme')
    <button type="button" class="{{ $class(false) }}" data-controller="theme" data-action="theme#toggle" aria-label="Тема">
        <x-ui.icon name="sun" class="on-light {{ $size }}"/><x-ui.icon name="moon" class="on-dark {{ $size }}"/>
    </button>
@endif
