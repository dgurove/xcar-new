{{-- Полоса действий: на телефоне плашка над таб-баром, от планшета — парящие кнопки справа внизу (CSS .action-bar). --}}
<div {{ $attributes->merge(['class' => 'action-bar']) }}>
    <div class="action-bar-inner">{{ $slot }}</div>
</div>
