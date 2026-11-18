{{-- Уведомление капсулой (18.11.2026, начальник: «слева, не огромными, в пару строк — аватарка, машина и текст»; владелец выбрал
     вариант «Капсула»): одна строка — лицо (аватар того, кто действовал, логотип вендора или значок события), машина, коротко что;
     наведение разворачивает её вширь до края экрана — весь текст и время. Без машины — заголовок и текст. Рисует сервер
     (`Live\Banner`), в угол ставит banner_controller: важное (лаймовая точка) висит до нажатия или смахивания, обычное уходит само.
     subject — объект: открытый объект гасит карточку везде. alarm — тревога: красный значок, закрытая сворачивается в капсулу
     тревоги шапки. notice — карточка уведомления (id строки ленты): нажатие отмечает его открытым с карточки (`via=banner`). --}}
@props(['id', 'title', 'text' => null, 'href' => null, 'user' => null, 'icon' => 'bell', 'important' => false, 'subject' => null, 'alarm' => false, 'notice' => false,
    'car' => null, 'brief' => null, 'vendor' => null])
@php
    $head = $car ?: $title;
    $what = $car ? ($brief ?: $title) : $text;
    // Текст, что уже стоит в «что» (письмо без шаблона — его первые слова), второй раз не пишем.
    $more = $car && $text && ! str_contains((string) $what, mb_substr($text, 0, 40)) ? $text : null;
@endphp
<div @class(['banner', 'banner--alarm' => $alarm]) data-banner-id="{{ $id }}" @if ($subject) data-subject="{{ $subject }}" @endif @if ($important) data-important @endif data-at="{{ now()->toIso8601String() }}" role="status" title="{{ $title }}{{ $text ? "\n".$text : '' }}">
    <a class="banner-main" @if ($href) href="{{ $href }}" @endif @if ($notice) data-notice="{{ $id }}" data-notice-via="banner" @endif>
        @if ($user)
            <x-ui.avatar :user="$user" :size="24" class="banner-face"/>
        @elseif ($vendor)
            <x-vendor.logo :vendor="$vendor" class="banner-face banner-logo"/>
        @else
            <span class="banner-face banner-icon"><x-ui.icon :name="$icon" class="size-3.5"/></span>
        @endif
        <span class="banner-line"><span class="banner-car">{{ $head }}</span>@if ($what) <span class="banner-what">{{ $what }}</span>@endif @if ($more)<span class="banner-more">{{ $more }}</span>@endif</span>
        <time class="banner-time">сейчас</time>
        @if ($important)<span class="banner-dot" aria-hidden="true"></span>@endif
    </a>
    <button type="button" class="banner-close" aria-label="Закрыть"><x-ui.icon name="x" class="size-3"/></button>
</div>
