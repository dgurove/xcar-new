{{-- Значок интерфейса: пути — `App\Support\Icons` (Lucide ISC, Phosphor MIT; откуда каждый — там). На странице — ссылка на
     символ спрайта `/icons.svg`, в письмах и PDF — путь внутри (21.10.2026: 880 значков каталога были 244 КБ разметки).
     Свой размер в классах (size-4, w-5) важнее size-6 по умолчанию: в собранном CSS size-6 идёт позже и иначе побеждал бы. --}}
@props(['name', 'fill' => false])
@php $class = trim((string) $attributes->get('class', '')); @endphp
{!! \App\Support\Icons::svg($name, (bool) $fill, trim((preg_match('/\b(size|w|h)-/', $class) ? '' : 'size-6 ').$class), $attributes->except('class')->toHtml()) !!}
