{{-- Пустое состояние: фраза и, если есть куда, тихая кнопка. line — пустая группа внутри экрана: серой строкой
     под заголовком группы, кнопка справа. --}}
@props(['href' => null, 'link' => null, 'line' => false])
<div {{ $attributes->merge(['class' => $line ? 'empty-line' : 'empty']) }}>
    <p>{{ $slot }}</p>
    @if ($href && $link)<a href="{{ $href }}" class="btn btn-s btn-quiet">{{ $link }}</a>@endif
</div>
