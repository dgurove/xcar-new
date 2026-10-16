{{-- Телефон человека ссылкой-звонком в строке текста: цифрами, лаймовым словом, не переносится. --}}
@props(['user'])
<a href="tel:+{{ $user->phone }}" {{ $attributes->merge(['class' => 'nums whitespace-nowrap text-accent-text']) }}>{{ $user->phoneFormatted() }}</a>
