{{-- Карточка уведомления, как в macOS и iOS: слева тот, кто действовал (аватар), или значок события, справа заголовок,
     время и текст. Рисует сервер (`Live\Banner`), в угол ставит banner_controller: важное висит до нажатия или смахивания,
     обычное уходит само. subject — объект: новая карточка о нём заменяет прежнюю, открытый объект гасит её везде. --}}
@props(['id', 'title', 'text' => null, 'href' => null, 'user' => null, 'icon' => 'bell', 'important' => false, 'subject' => null])
<div class="banner" data-banner-id="{{ $id }}" @if ($subject) data-subject="{{ $subject }}" @endif @if ($important) data-important @endif data-at="{{ now()->toIso8601String() }}" role="status">
    <a class="banner-main" @if ($href) href="{{ $href }}" @endif>
        @if ($user)<x-ui.avatar :user="$user" :size="44"/>@else<x-ui.row-icon :name="$icon"/>@endif
        <span class="banner-body">
            <span class="banner-head"><span class="banner-title">{{ $title }}</span><time class="banner-time">сейчас</time></span>
            @if ($text)<span class="banner-text">{{ $text }}</span>@endif
        </span>
    </a>
    <button type="button" class="banner-close" aria-label="Закрыть"><x-ui.icon name="x" class="size-3.5"/></button>
</div>
