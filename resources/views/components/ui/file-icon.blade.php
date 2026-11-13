{{-- Квадрат файла, цвет по типу: расширение и под ним мелко вес (13.11.2026, владелец: «[PDF] над [383 Кб] — всё в
     квадратик»; миниатюр у фото нет — тоже «JPG» с весом). bytes — вес; без него — одно расширение по центру. Маленький
     (`file-icon-s`, чипы) — без веса. --}}
@props(['name', 'mime' => null, 'bytes' => null])
@php
    // Имя без расширения с точкой в дате («Счёт поставщика от 10.11.2026») — тип по mime, а не «2026».
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    // Без расширения — по mime; вложения письма «text/plain» и «message/rfc822» — «txt» и «eml», а не «plai» и «rfc8» (12.11.2026).
    $ext = $ext !== '' && ! ctype_digit($ext) ? $ext : match ((string) $mime) {
        'text/plain' => 'txt',
        'message/rfc822' => 'eml',
        'text/html' => 'html',
        default => explode('/', (string) $mime)[1] ?? '',
    };
    $kind = match (true) {
        $ext === 'pdf' => 'pdf',
        in_array($ext, ['xls', 'xlsx', 'csv', 'ods']) => 'sheet',
        in_array($ext, ['doc', 'docx', 'rtf', 'odt', 'txt']) => 'doc',
        in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar']) => 'zip',
        str_starts_with((string) $mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif']) => 'image',
        default => 'other',
    };
    $size = $bytes !== null ? \App\Support\FileSize::short((int) $bytes) : '';
@endphp
<span {{ $attributes->merge(['class' => 'file-icon'.($kind !== 'other' ? " file-icon-$kind" : '').($size !== '' ? ' file-icon-sized' : '')]) }}>
    <span class="file-icon-ext">{{ mb_substr($ext === 'jpeg' ? 'jpg' : $ext, 0, 4) ?: '?' }}</span>
    @if ($size !== '')<span class="file-icon-size">{{ $size }}</span>@endif
</span>
