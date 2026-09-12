<x-ui.file :name="$name" data-file>
    <input type="hidden" name="files[]" value="{{ $path }}">
    <button type="button" class="btn btn-ghost btn-s px-2 text-ink-muted" data-action="files#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
</x-ui.file>
