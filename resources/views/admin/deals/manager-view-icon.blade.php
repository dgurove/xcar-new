{{-- Значок пункта окна «Что видит менеджер»: контакт — человечком, файл — квадратом типа (`x-ui.file-icon`). --}}
@if ($i['icon'])<x-ui.row-icon :name="$i['icon']" size="s"/>@else<x-ui.file-icon :name="$i['file'] ?? 'file.pdf'" :mime="$i['media']?->mime_type"/>@endif
