{{-- Шторка «Водяной знак» поверх просмотрщика (photos_controller#markSheet): кадр, «Без знака / Со знаком», если копия со
     знаком есть, «Вернуть со знаком» и «Заменить своим файлом» — почистили знак сами, кадр меняется на месте. Знак не
     снят — строки «Снять «площадка»» по реестру: с этого фото и со всех, с которых ещё не снимали ($rest). --}}
@php
    $url = "/offers/{$offer->number}/media/{$media->id}";
    $state = $manual ? 'Почищено вручную' : ($title ? "Знак «{$title}» снят" : 'Знак не снят');
@endphp
<div data-mark-sheet>
    <div class="mb-4 flex items-center justify-between gap-3">
        <h2 class="text-lg">Водяной знак</h2>
        <button type="button" class="sheet-close ml-auto" data-mark-close aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
    </div>
    <div class="mark-view">
        <img src="{{ \App\Media\MediaUrl::for($media) }}" data-clean="{{ \App\Media\MediaUrl::for($media) }}" @if ($marked) data-marked="{{ $url }}/marked?v={{ $media->updated_at?->timestamp }}" @endif alt="">
    </div>
    @if ($marked)
        <div class="mt-3 flex gap-2">
            <button type="button" class="pill" aria-current="true" data-mark-show="clean">Без знака</button>
            <button type="button" class="pill" data-mark-show="marked">Со знаком</button>
        </div>
    @endif
    <div class="list mt-4">
        <div class="row">
            <x-ui.row-icon :name="$title || $manual ? 'check' : 'eye-off'" :tone="$title || $manual ? 'open' : 'muted'" size="s"/>
            <span class="min-w-0 flex-1">{{ $state }}</span>
        </div>
        @unless ($title || $manual)
            @foreach ($marks as $name => $markTitle)
                <button type="button" class="row w-full text-left" data-mark-act="mark" data-mark="{{ $name }}">
                    <x-ui.row-icon name="check" tone="accent" size="s"/>
                    <span class="min-w-0 flex-1">Снять «{{ $markTitle }}»</span>
                </button>
                @if (count($rest) > 1)
                    <button type="button" class="row w-full text-left" data-mark-all="{{ implode(',', $rest) }}" data-mark="{{ $name }}">
                        <x-ui.row-icon name="photo" tone="accent" size="s"/>
                        <span class="min-w-0 flex-1" data-mark-label>Снять «{{ $markTitle }}» со всех фото</span>
                        <span class="text-sm text-ink-dim">{{ count($rest) }}</span>
                    </button>
                @endif
            @endforeach
        @endunless
        @if ($marked)
            <button type="button" class="row w-full text-left" data-mark-act="undo" data-confirm="Вернуть кадр со знаком?">
                <x-ui.row-icon name="undo" size="s"/>
                <span class="min-w-0 flex-1">Вернуть со знаком</span>
            </button>
        @endif
        <label class="row">
            <x-ui.row-icon name="photo" size="s"/>
            <span class="min-w-0 flex-1">Заменить своим файлом</span>
            <input type="file" accept="image/*,.heic,.heif" class="hidden" data-mark-file>
        </label>
    </div>
</div>
