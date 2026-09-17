{{-- «Рекомендуем»: синий кружок с белой галочкой сразу после названия машины; с label — тег с подписью
     (страница предложения, окошко). Рисуется только у $offer->recommended — проверяет вызывающий. --}}
@props(['label' => false])
@if ($label)
<span {{ $attributes->merge(['class' => 'tag tag-recommended']) }}>
    <svg viewBox="0 0 24 24" aria-hidden="true" class="mr-1 size-3.5"><circle cx="12" cy="12" r="11" fill="var(--color-recommended)"/><path d="m7.4 12.4 3.1 3.1 6.2-6.6" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>Рекомендуем
</span>
@else
<span {{ $attributes->merge(['class' => 'recommended']) }} role="img" aria-label="Рекомендуем" title="Рекомендуем"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="var(--color-recommended)"/><path d="m7.4 12.4 3.1 3.1 6.2-6.6" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
@endif
