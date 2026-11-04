{{-- Архив во вложении письма (04.11.2026, владелец: «архив ТИТ должен распаковываться, а не тупо в документы попадать»):
     кадры — лентой, как фото письма (миниатюра записи `?entry=N&thumb=1` считается раз), остальное внутри — документами,
     сам архив — подписью с числом кадров и «Скачать». Записи — `Docs::archive` (архив на диске; нет — строкой-документом). --}}
@props(['file', 'entries', 'base' => '/mail'])
@php
    use App\Mail\Extraction\AttachmentClassifier;
    use App\Support\Docs;
    [$shots, $papers] = collect($entries)->partition(fn ($e) => Docs::type(null, basename($e['name'])) === 'image' && AttachmentClassifier::kindOf(basename($e['name'])) === null);
    $url = fn ($e) => "{$base}/attachments/{$file->id}?entry={$e['index']}";
@endphp
<div class="mt-3">
    <div class="flex flex-wrap items-center gap-x-2 text-sm text-ink-muted">
        <x-ui.icon name="clip" class="size-3.5"/>
        <span class="min-w-0 truncate" title="{{ $file->filename }}">{{ $file->filename }}</span>
        @if ($shots->isNotEmpty())<span class="nums">{{ $shots->count() }} фото</span>@endif
        <a href="{{ $base }}/attachments/{{ $file->id }}" download class="letter-link">Скачать</a>
    </div>
    @if ($shots->isNotEmpty())
        <div class="photo-row mt-2" data-controller="photos" data-photos-readonly-value="true">
            <div class="photo-strip" data-photos-target="grid">
                @foreach ($shots as $e)
                    <div class="photo-cell" title="{{ basename($e['name']) }}"><img src="{{ $url($e) }}&thumb=1" data-full="{{ $url($e) }}&large=1" data-download="{{ $url($e) }}" alt="" loading="lazy" data-action="click->photos#open"></div>
                @endforeach
            </div>
        </div>
    @endif
    @if ($papers->isNotEmpty())
        <div class="mt-2 flex flex-wrap gap-2">
            @foreach ($papers as $e)
                <a href="{{ $url($e) }}" target="_blank" class="flex max-w-full items-center gap-2 rounded-(--radius-m) bg-surface-2 p-2 pr-3" data-doc="{{ Docs::type(null, basename($e['name'])) }}" data-doc-name="{{ Docs::label(basename($e['name'])) }}" title="{{ basename($e['name']) }}">
                    <x-ui.file-icon :name="basename($e['name'])" :mime="null"/>
                    <span class="min-w-0"><span class="block truncate text-sm">{{ basename($e['name']) }}</span><span class="text-xs text-ink-muted">{{ Docs::size($e['size']) }}</span></span>
                </a>
            @endforeach
        </div>
    @endif
</div>
