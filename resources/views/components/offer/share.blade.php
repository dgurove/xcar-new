{{-- Поделиться: текст из отмеченных строк в буфер, PDF из отмеченных фото через navigator.share. --}}
@props(['offer', 'variant' => 'secondary', 'size' => null])
@php
    $user = auth()->user();
    $fields = \App\Offers\Share\Caption::fields($offer, $user);
    $photos = $offer->visiblePhotos();
    $id = 'share-'.$offer->number;
@endphp
<div data-controller="share" data-share-url-value="/offers/{{ $offer->number }}/pdf" data-share-vat-value="{{ \App\Offers\Share\Caption::vatMark($offer) }}" data-share-name-value="{{ $offer->number }} {{ $offer->titleWithYear() }}.pdf" class="contents">
    <x-ui.button type="button" :variant="$variant" :size="$size" data-action="share#open" {{ $attributes }}><x-ui.icon name="share" class="size-5"/> Поделиться</x-ui.button>
    <dialog id="{{ $id }}" class="sheet" data-share-target="dialog" data-action="click->share#backdrop">
        <div class="flex items-start justify-between gap-4">
            <h2 class="text-lg">Поделиться</h2>
            <button type="button" class="btn btn-ghost btn-sm -mr-2 -mt-1 ml-auto" data-action="share#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-5"/></button>
        </div>
        <div class="mt-3 flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                @foreach ($fields as $f)
                    <label class="check"><input type="checkbox" data-share-target="field" data-key="{{ $f['key'] }}" data-value="{{ $f['value'] }}" @checked($f['on']) data-action="share#compose"><span>{{ $f['label'] }} <span class="text-ink-muted">{{ \Illuminate\Support\Str::limit($f['value'], 40) }}</span></span></label>
                @endforeach
            </div>
            <pre class="card-nested whitespace-pre-wrap font-sans text-sm" data-share-target="preview"></pre>
            @if ($photos->isNotEmpty())
                <div class="grid grid-cols-4 gap-1.5">
                    @foreach ($photos->take(30) as $i => $media)
                        <label class="relative aspect-[4/3] cursor-pointer overflow-hidden rounded-(--radius-s) bg-surface-3">
                            <input type="checkbox" class="peer sr-only" value="{{ $media->id }}" data-share-target="photo" @checked($i < 6) data-action="share#photosChanged">
                            <img src="{{ \App\Media\MediaUrl::for($media, 'thumb') }}" alt="" class="size-full object-cover opacity-40 peer-checked:opacity-100" loading="lazy">
                            <span class="absolute right-1 top-1 hidden size-5 items-center justify-center rounded-full bg-accent text-white peer-checked:flex"><x-ui.icon name="check" class="size-3.5"/></span>
                        </label>
                    @endforeach
                </div>
                @if ($user?->isStaff())
                    <label class="check"><input type="checkbox" checked data-share-target="watermark" data-action="share#photosChanged"><span>Водяной знак на фото</span></label>
                @endif
            @endif
            <div class="text-sm text-ink-muted" data-share-target="status"></div>
            <div class="flex flex-col gap-2">
                <x-ui.button type="button" block data-action="share#send" data-share-target="send"><x-ui.icon name="send" class="size-5"/> Отправить</x-ui.button>
                <div class="flex gap-2">
                    <x-ui.button type="button" variant="secondary" class="flex-1" data-action="share#copy">Скопировать текст</x-ui.button>
                    @if ($photos->isNotEmpty())<x-ui.button type="button" variant="secondary" class="flex-1" data-action="share#download" data-share-target="download">Скачать PDF</x-ui.button>@endif
                </div>
            </div>
        </div>
    </dialog>
</div>
