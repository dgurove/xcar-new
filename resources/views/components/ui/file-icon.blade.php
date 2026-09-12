{{-- Квадрат с расширением файла, цвет по типу; картинка — миниатюрой, если есть. --}}
@props(['name', 'mime' => null, 'thumb' => null])
@php
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: (explode('/', (string) $mime)[1] ?? ''));
    $kind = match (true) {
        $ext === 'pdf' => 'pdf',
        in_array($ext, ['xls', 'xlsx', 'csv', 'ods']) => 'sheet',
        in_array($ext, ['doc', 'docx', 'rtf', 'odt', 'txt']) => 'doc',
        in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar']) => 'zip',
        str_starts_with((string) $mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif']) => 'image',
        default => 'other',
    };
@endphp
<span {{ $attributes->merge(['class' => 'file-icon'.($kind !== 'image' && $kind !== 'other' ? " file-icon-$kind" : '')]) }}>
    @if ($kind === 'image' && $thumb)<img src="{{ $thumb }}" alt="" loading="lazy">
    @elseif ($kind === 'image')<x-ui.icon name="photo" class="size-5"/>
    @else{{ mb_substr($ext, 0, 4) ?: '?' }}@endif
</span>
