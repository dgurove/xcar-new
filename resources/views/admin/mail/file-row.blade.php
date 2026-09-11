<div class="flex items-center gap-2 py-1" data-file>
    <input type="hidden" name="files[]" value="{{ $path }}">
    <x-ui.icon name="file" class="size-5 shrink-0 text-ink-muted"/>
    <span class="flex-1 truncate">{{ $name }}</span>
    <button type="button" class="btn btn-ghost btn-s px-2 text-ink-muted" data-action="files#remove" aria-label="Убрать"><x-ui.icon name="x" class="size-5"/></button>
</div>
