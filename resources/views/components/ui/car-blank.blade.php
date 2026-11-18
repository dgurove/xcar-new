{{-- Заглушка вместо фото: силуэт ТС на мягком фоне (Phosphor fill, MIT). icon — значок типа ТС (`Cars\Category::icon()`:
     фургон, грузовик, прицеп, спецтехника, мотоцикл); без него — легковой. Тот же вид у кадра, который не открылся
     (`lib/photo-fallback.js`). --}}
@props(['icon' => null])
@php $shape = \App\Support\Icons::SOLID[$icon ?: 'cat-car'] ?? \App\Support\Icons::SOLID['cat-car']; @endphp
<span {{ $attributes->merge(['class' => 'car-blank']) }} aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="currentColor"><path d="{{ $shape[1] ?? $shape[0] }}"/></svg>
</span>
