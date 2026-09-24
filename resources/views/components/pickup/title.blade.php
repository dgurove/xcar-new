{{-- Заголовок страниц покупателя: что это за страница мелко, сама ТС крупно, госномер. --}}
@props(['vehicle', 'eyebrow'])
<div class="text-sm text-ink-muted">{{ $eyebrow }}</div>
<h1 class="mt-1 text-2xl leading-tight">{{ $vehicle->titleWithYear() }}</h1>
@if ($vehicle->plate)<div class="nums mt-1 text-ink-muted">{{ $vehicle->plate }}</div>@endif
