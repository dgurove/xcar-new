{{-- Разделитель дня в ленте чата: «Сегодня», «Вчера», «5 октября», прошлый год — с годом. --}}
@props(['at'])
<div class="chat-day" data-day="{{ $at->toDateString() }}">{{ $at->isToday() ? 'Сегодня' : ($at->isYesterday() ? 'Вчера' : $at->translatedFormat($at->isCurrentYear() ? 'j F' : 'j F Y')) }}</div>
