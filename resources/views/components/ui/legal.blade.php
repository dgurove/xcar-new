{{-- Юридическая страница: заголовок, дата редакции, текст в .legal. --}}
@props(['title', 'heading'])
<x-ui.shell :title="$title" :heading="false" :trail="[['Главная', '/'], [$title]]" narrow>
    <h1 class="text-[32px] sm:text-[40px]">{{ $heading }}</h1>
    <p class="nums mt-3 text-sm font-normal text-ink-dim">Редакция от {{ \Carbon\Carbon::parse('2026-09-10')->translatedFormat('j F Y') }} года</p>
    <div class="legal mt-10">{{ $slot }}</div>
</x-ui.shell>
