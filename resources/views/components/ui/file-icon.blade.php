{{-- Квадрат с расширением файла, цвет по типу; картинка — миниатюрой, если есть. --}}
@props(['name', 'mime' => null, 'thumb' => null])
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
@endphp
<span {{ $attributes->merge(['class' => 'file-icon'.($kind !== 'image' && $kind !== 'other' ? " file-icon-$kind" : '')]) }}>
    @if ($kind === 'image' && $thumb)<img src="{{ $thumb }}" alt="" loading="lazy">
    @elseif ($kind === 'image')<x-ui.icon name="photo" class="size-5"/>
    @else{{ mb_substr($ext, 0, 4) ?: '?' }}@endif
</span>
