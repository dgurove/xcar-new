{{-- Скан заявки страховой рядом с полями: PDF рисует браузер (Imagick в образе нет), остальные файлы — ссылками. --}}
@props(['scans', 'base' => '/mail'])
@php $scan = $scans->first(); @endphp
<x-ui.card title="Скан заявки">
    <iframe src="{{ $base }}/attachments/{{ $scan->id }}#toolbar=0&view=FitH" title="{{ $scan->filename }}" loading="lazy" class="aspect-3/4 max-h-[70vh] w-full rounded-(--radius-m) bg-surface-2"></iframe>
    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
        @foreach ($scans as $file)
            <a href="{{ $base }}/attachments/{{ $file->id }}" target="_blank" class="min-w-0 truncate {{ $loop->first ? 'text-ink-muted' : 'text-ink-dim' }} hover:text-ink">{{ $file->filename }}</a>
        @endforeach
    </div>
</x-ui.card>
