{{-- Кружок человека: фото или инициалы. user — сотрудник или покупатель; без него — name/email (отправитель письма):
     инициалы из имени, иначе первая буква адреса, тон фона по хэшу строки (avatar-hue). online — зелёная точка в углу. --}}
@props(['user' => null, 'name' => null, 'email' => null, 'size' => 40, 'online' => false])
@php
    $url = $user?->avatarUrl();
    $text = $user?->initials();
    $hue = null;
    if (! $user) {
        $label = trim((string) ($name ?: $email));
        $parts = array_slice(preg_split('/[\s,]+/u', preg_replace('/\(.*?\)|"|<.*?>/u', '', $label)) ?: [], 0, 2);
        $text = mb_strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_filter($parts)))) ?: mb_strtoupper(mb_substr((string) $email, 0, 1)) ?: '·';
        $hue = (crc32(mb_strtolower((string) ($email ?: $name))) % 8) + 1;
    }
@endphp
<span {{ $attributes->merge(['class' => 'avatar'.($online ? ' is-online' : '').($hue ? ' avatar-hue-'.$hue : '')]) }} style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ max(9, (int) round($size * .4)) }}px">
    @if ($url)<img src="{{ $url }}" alt="" width="{{ $size }}" height="{{ $size }}">@else{{ $text ?: '·' }}@endif
</span>
