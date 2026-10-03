{{-- Наклейка-ёжик на углу кнопки: «NEW!» у нового действия. Родителю — relative. --}}
<span {{ $attributes->class('burst') }} aria-hidden="true">{{ $slot->isEmpty() ? 'NEW!' : $slot }}</span>
