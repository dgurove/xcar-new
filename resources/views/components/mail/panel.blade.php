{{-- Письма рядом с формой: все письма веток ТС или кандидата, новые сверху, каждое целиком с вложениями.
     На десктопе — вторая колонка, на телефоне — шторка «Письма N» (сам вызывающий оборачивает). --}}
@props(['messages', 'base' => '/mail'])
@php $messages = collect($messages)->sortByDesc(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->values(); @endphp
<div {{ $attributes->merge(['class' => 'flex flex-col gap-3']) }}>
    @forelse ($messages as $m)
        <x-mail.message :message="$m" :base="$base" compact/>
    @empty
        <x-ui.empty>Писем нет</x-ui.empty>
    @endforelse
</div>
