{{-- Сколько ещё висит в галерее: «осталось 2 д 6 ч», в последние сутки оранжевым; word — слово перед числом. --}}
@props(['offer', 'word' => 'осталось', 'done' => 'закрыта'])
<span {{ $attributes->merge(['class' => 'nums '.($offer->isGalleryEndingSoon() ? 'text-urgent' : 'text-accent-text')]) }} data-controller="timer" data-timer-until-value="{{ $offer->gallery_until->toIso8601String() }}" data-timer-done-value="{{ $done }}" data-timer-coarse-value="true" data-timer-word-value="{{ $word }}">{{ \App\Support\Ago::left($offer->gallery_until, $word) }}</span>
