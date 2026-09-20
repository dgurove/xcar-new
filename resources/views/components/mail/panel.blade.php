{{-- Письма подряд: все письма ветки или всех веток ТС, новые сверху, каждое целиком с вложениями.
     Живёт в окне писем (x-mail.window); reply — «Ответить» под письмом раскрывает редактор во фрейме. --}}
@props(['messages', 'base' => '/mail', 'reply' => false])
@php $messages = collect($messages)->sortByDesc(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->values(); @endphp
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }}>
    @forelse ($messages as $m)
        <x-mail.message :message="$m" :base="$base" :reply="$reply" compact/>
    @empty
        <x-ui.empty>Писем нет</x-ui.empty>
    @endforelse
</div>
