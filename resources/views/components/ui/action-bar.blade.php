{{-- Полоса действий: на телефоне плашка над таб-баром, от планшета — парящие кнопки справа внизу (CSS .action-bar).
     tag="form" — полоса сама форма (method, action — атрибутами): одна кнопка отправляет её. --}}
@props(['tag' => 'div'])
<{{ $tag }} {{ $attributes->merge(['class' => 'action-bar']) }}>
    <div class="action-bar-inner">{{ $slot }}</div>
</{{ $tag }}>
