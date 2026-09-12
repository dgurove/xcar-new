{{-- Экраны запуска iOS: под каждый iPhone своя картинка (iOS требует точный размер), светлая и тёмная.
     Список экранов тот же, что в scripts/icons.mjs; картинки — public/pwa/splash. --}}
@php
$screens = [
    [440, 956, 3], [402, 874, 3], [430, 932, 3], [393, 852, 3], [428, 926, 3], [420, 912, 3],
    [390, 844, 3], [375, 812, 3], [414, 896, 3], [414, 896, 2], [414, 736, 3], [375, 667, 2],
];
@endphp
@foreach ($screens as [$w, $h, $r])
@foreach (['light' => '', 'dark' => '-dark'] as $scheme => $suffix)
    <link rel="apple-touch-startup-image" href="/pwa/splash/splash-{{ $w * $r }}x{{ $h * $r }}{{ $suffix }}.png" media="(device-width: {{ $w }}px) and (device-height: {{ $h }}px) and (-webkit-device-pixel-ratio: {{ $r }}) and (orientation: portrait) and (prefers-color-scheme: {{ $scheme }})">
@endforeach
@endforeach
