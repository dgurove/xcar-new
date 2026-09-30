{{-- Сообщение бота в сцене чата: то же, что придёт в Telegram (Telegram\Preview), с «печатает…» перед ним.
     Строка с VIN — HtmlString (<code>), {{ }} выводит её как есть, остальное экранирует. --}}
@props(['message'])
<div class="tg-scene" data-telegram-target="scene">
    <img src="/pwa/site/icon-maskable-512.png" alt="" class="tg-avatar">
    <div class="tg-message">
        <span class="tg-typing" aria-hidden="true"><i></i><i></i><i></i></span>
        <div class="tg-bubble">
            <b>{{ $message['title'] }}</b>
            @foreach ($message['lines'] as $line)<br>{{ $line }}@endforeach
            <span class="tg-bubble-meta nums">{{ now()->format('H:i') }}</span>
        </div>
        <div class="tg-key">{{ $message['button'] }}</div>
    </div>
</div>
