{{-- Поделиться оффером или машиной закупки: текст из отмеченных строк в буфер, PDF из отмеченных фото — в системный лист «Поделиться» (и на компьютере), скачать — строкой «PDF готов», или те же фото альбомом. --}}
@props(['subject', 'variant' => 'secondary', 'size' => null, 'icon' => false])
@php
    $user = auth()->user();
    $fields = $subject->fields($user);
    $photos = $subject->photos();
    // Все кадры, группами «от страховой» / «при приёме» (02.11.2026, владелец: ограничение в 30 — «дебильное»); отмечены
    // по умолчанию первые 10 — как у готового PDF бота.
    $groups = $photos->isNotEmpty() ? $subject->photoGroups() : [];
    $nth = 0;
    $id = 'share-'.$subject->model->getTable().'-'.$subject->model->getKey();
    // Иконкой — круглая кнопка; свой класс (полоса карточки строки) заменяет его целиком.
    $iconClass = $attributes->get('class') ?: 'btn btn-s btn-quiet btn-round';
    $iconSize = $attributes->get('class') ? 'size-[18px]' : 'size-5';
@endphp
@if ($subject->locked($user))
    {{-- Запрет шеринга: кнопка на месте, но серая; нажатие — тост с причиной. Не disabled — у .btn он режет клики. --}}
    <div data-controller="share" data-share-locked-value="{{ \App\Offers\Share\Subject::LOCKED }}" class="contents">
        @if ($icon)
            <button type="button" class="{{ $iconClass }}" aria-disabled="true" data-action="share#locked" aria-label="Поделиться" title="Поделиться" {{ $attributes->except('class') }}><x-ui.icon name="share" :class="$iconSize"/></button>
        @else
            <x-ui.button type="button" :variant="$variant" :size="$size" aria-disabled="true" data-action="share#locked" {{ $attributes }}><x-ui.icon name="share" class="size-5"/> Поделиться</x-ui.button>
        @endif
    </div>
@else
<div data-controller="share" data-share-url-value="{{ $subject->url }}" data-share-photo-url-value="{{ $subject->photoUrl() }}" data-share-warm-url-value="{{ preg_replace('~/pdf$~', '/photos/warm', $subject->url) }}" data-share-zip-url-value="{{ preg_replace('~/pdf$~', '/zip', $subject->url) }}" data-share-zip-name-value="{{ $subject->zipName() }}" data-share-vat-value="{{ $subject->vatMark() }}" data-share-name-value="{{ $subject->fileName() }}" class="contents">
    @if ($icon)
        <button type="button" class="{{ $iconClass }}" data-action="share#open" aria-label="Поделиться" title="Поделиться" {{ $attributes->except('class') }}><x-ui.icon name="share" :class="$iconSize"/></button>
    @else
        <x-ui.button type="button" :variant="$variant" :size="$size" data-action="share#open" {{ $attributes }}><x-ui.icon name="share" class="size-5"/> Поделиться</x-ui.button>
    @endif
    <dialog id="{{ $id }}" class="sheet sheet-wide" data-share-target="dialog" data-action="click->share#backdrop">
        <div class="mb-4 flex items-center justify-between gap-4">
            <h2 class="text-lg">Поделиться</h2>
            <button type="button" class="sheet-close ml-auto" data-action="share#close" aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
        </div>
        {{-- Сверху — что отправить словами (строки, текст, ссылка) и кнопки отправки, в самом низу — кадры, их бывают сотни
             (02.11.2026, владелец: «то, что может быть огромного количества, всегда снизу», «кнопки над фотками, чтобы до них не
             листать»). --}}
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2">
                @foreach ($fields as $f)
                    <label class="check"><input type="checkbox" data-share-target="field" data-key="{{ $f['key'] }}" data-value="{{ $f['value'] }}" @if ($f['group'] ?? null) data-group="{{ $f['group'] }}" @endif @checked($f['on']) data-action="share#pick"><span>{{ $f['label'] }} <span class="text-ink-muted">{{ \Illuminate\Support\Str::limit($f['value'], 40) }}</span></span></label>
                @endforeach
            </div>
            {{-- Получившийся текст — с копированием в углу. --}}
            <div class="vin-box">
                <pre class="box-nested min-h-12 whitespace-pre-wrap pr-14 font-sans text-sm" data-share-target="preview"></pre>
                <button type="button" class="vin-magic field-copy bottom-auto h-12" data-action="share#copy" aria-label="Скопировать текст" title="Скопировать текст"><x-ui.icon name="copy" class="size-5"/></button>
            </div>
            @php $link = collect($fields)->firstWhere('key', 'link')['value'] ?? null; @endphp
            @if ($link)
                <div class="vin-box" data-controller="copy" data-copy-done-value="Ссылка в буфере">
                    <input type="text" readonly class="field-input" value="{{ $link }}" aria-label="Ссылка" data-copy-target="field" data-action="click->copy#copy">
                    <button type="button" class="vin-magic field-copy" data-copy-target="button" data-action="copy#copy" aria-label="Скопировать ссылку" title="Скопировать ссылку"><x-ui.icon name="copy" class="size-5"/></button>
                </div>
            @endif
            <div class="flex flex-col gap-2">
                {{-- «PDF готов, 6 фото, 619 КБ» — нажатие скачивает файл (значок сразу за текстом); «Готовим фото 30 из 240» — ход. --}}
                <button type="button" class="inline-flex min-h-5 items-center gap-1.5 self-start text-left text-sm text-ink-muted enabled:hover:text-ink" data-share-target="statusRow" data-action="share#downloadPdf" disabled>
                    <span data-share-target="status"></span>
                    <span class="hidden shrink-0" data-share-target="statusIcon"><x-ui.icon name="download" class="size-4"/></span>
                </button>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <x-ui.button type="button" class="min-w-0 sm:flex-1" data-action="share#send" data-share-target="send"><x-ui.icon name="share" class="size-5"/> <span data-share-target="label">Поделиться PDF</span></x-ui.button>
                    {{-- Те же кадры альбомом (до 10) или архивом: где документы не качаются, фото доходят. --}}
                    @if ($photos->isNotEmpty())<x-ui.button type="button" variant="secondary" class="min-w-0 sm:flex-1" data-action="share#sendPhotos" data-share-target="photosSend"><x-ui.icon name="share" class="size-5"/> <span data-share-target="photosLabel">Поделиться фото</span></x-ui.button>@endif
                </div>
            </div>
            @if ($photos->isNotEmpty())
                <label class="check"><input type="checkbox" data-share-target="watermark" data-action="share#photosChanged"><span>Водяной знак на фото</span></label>
            @endif
            @foreach ($groups as $g)
                <div class="flex flex-col gap-1.5" data-share-target="group">
                    <div class="flex items-center justify-between">
                        <span class="text-sm text-ink-muted">{{ $g['label'] ?? 'Фото' }} <span class="nums">{{ $g['photos']->count() }}</span></span>
                        <button type="button" class="pill pill-plain" data-share-target="all" data-action="share#toggleAll">Выбрать все</button>
                    </div>
                    <div class="grid grid-cols-4 gap-1.5 sm:grid-cols-5">
                        @foreach ($g['photos'] as $media)
                            <label class="relative aspect-[4/3] cursor-pointer overflow-hidden rounded-(--radius-s) bg-surface-3">
                                <input type="checkbox" class="peer sr-only" value="{{ $media->id }}" data-share-target="photo" @checked($nth++ < \App\Offers\Share\SharePdf::PREPARED) data-action="share#photosChanged">
                                <img src="{{ \App\Media\MediaUrl::for($media, 'thumb') }}" alt="" class="size-full object-cover opacity-40 peer-checked:opacity-100" loading="lazy">
                                <span class="absolute right-1 top-1 hidden size-5 items-center justify-center rounded-full bg-accent text-white peer-checked:flex"><x-ui.icon name="check" class="size-3.5"/></span>
                                {{-- Скрыт с витрины (глазик) — отдать можно, но видно, что на сайте его нет. --}}
                                @if (\App\Media\Hidden::is($media))<span class="absolute bottom-1 left-1 flex size-5 items-center justify-center rounded-full bg-black/55 text-white" title="Скрыто на сайте"><x-ui.icon name="eye-off" class="size-3.5"/></span>@endif
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </dialog>
</div>
@endif
