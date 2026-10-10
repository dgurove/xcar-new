{{-- Юридическая страница: заголовок, дата редакции, текст в .legal. --}}
@props(['title', 'heading'])
<x-ui.shell :title="$title" :heading="false" :trail="[['Главная', '/'], [$title]]" narrow>
    <h1 class="text-[32px] sm:text-[40px]">{{ $heading }}</h1>
    <x-legal.edition class="mt-3"/>
    <div class="legal mt-10">{{ $slot }}</div>
</x-ui.shell>
