{{-- Документы черновика у поставщика: «Добавить документ» и список; имя — своё у своих, у остальных «Документ N»; корзина —
     только у своих. Адрес — свой (`/files/{id}`), просмотр — шторкой документов. --}}
@php $papers = \App\Http\Partner\MediaController::shown($offer)['papers']; $me = auth()->id(); @endphp
<div id="partner-papers" data-controller="photos" data-photos-url-value="/offers/{{ $offer->id }}/media" data-photos-collection-value="papers">
    <div class="mb-2 flex items-center gap-2"><label class="btn btn-quiet btn-s relative cursor-pointer"><input type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.doc,.docx,.xls,.xlsx" multiple class="sr-only" data-photos-target="input" data-action="change->photos#upload"><x-ui.icon name="plus" class="size-4"/> Добавить документ</label></div>
    <div hidden data-photos-target="progress" class="mb-3">
        <div class="mb-1 text-sm text-ink-muted" data-label></div>
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
    </div>
    <div class="flex flex-col">
        @foreach ($papers as $i => $media)
            <x-ui.file :name="\App\Http\Partner\MediaController::paperName($media, $i + 1)" :mime="$media->mime_type" :bytes="$media->size" href="/files/{{ $media->id }}">
                @if ((int) $media->getCustomProperty('partner') === $me)
                    <form method="post" action="/offers/{{ $offer->id }}/media/{{ $media->id }}" data-turbo-confirm="Удалить документ?">@csrf @method('delete')<button class="btn btn-ghost btn-s px-2 text-ink-muted" aria-label="Удалить"><x-ui.icon name="trash" class="size-5"/></button></form>
                @endif
            </x-ui.file>
        @endforeach
    </div>
</div>
