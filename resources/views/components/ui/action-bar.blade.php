{{-- Полоса действий: на телефоне над таб-баром, от планшета липнет к низу окна. --}}
<div {{ $attributes->merge(['class' => 'action-bar']) }}>
    <div class="action-bar-inner">{{ $slot }}</div>
</div>
