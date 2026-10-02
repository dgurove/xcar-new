{{-- Шторка «Водяной знак» поверх просмотрщика (photos_controller#markSheet): кадр, «Без знака / Со знаком», если копия со
     знаком есть, «Вернуть со знаком» и «Заменить своим файлом» — почистили знак сами, кадр меняется на месте. Знак не
     снят — строки «Снять «площадка»» по реестру: с этого фото и со всех, с которых ещё не снимали ($rest), и «Новый
     знак»: рамка по кадру и название площадки, знак собирается по фото предложения (`OfferPhotoController::learn`). --}}
@php
    $url = "/offers/{$offer->number}/media/{$media->id}";
    $state = $manual ? 'Почищено вручную' : ($title ? "Знак «{$title}» снят" : 'Знак не снят');
@endphp
<div data-mark-sheet>
    <div class="mb-4 flex items-center justify-between gap-3">
        <h2 class="text-lg" data-mark-head>Водяной знак</h2>
        <button type="button" class="sheet-close ml-auto" data-mark-close aria-label="Закрыть"><x-ui.icon name="x" class="size-[18px]"/></button>
    </div>
    <div class="mark-view">
        <div class="mark-frame" data-mark-frame><span class="mark-box" hidden data-mark-box></span><img src="{{ \App\Media\MediaUrl::for($media) }}" data-clean="{{ \App\Media\MediaUrl::for($media) }}" @if ($marked) data-marked="{{ $url }}/marked?v={{ $media->updated_at?->timestamp }}" @endif alt=""></div>
    </div>
    @if ($marked)
        <div class="mt-3 flex gap-2" data-mark-main>
            <button type="button" class="pill" aria-current="true" data-mark-show="clean">Без знака</button>
            <button type="button" class="pill" data-mark-show="marked">Со знаком</button>
        </div>
    @endif
    <div class="list mt-4" data-mark-main>
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
            <button type="button" class="row w-full text-left" data-mark-learn-open>
                <x-ui.row-icon name="plus" size="s"/>
                <span class="min-w-0 flex-1">Новый знак</span>
            </button>
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
    @unless ($title || $manual)
        <div class="mt-4 flex flex-col gap-3" hidden data-mark-learn>
            <x-ui.field name="mark_title" label="Площадка" maxlength="40" data-mark-title/>
            <button type="button" class="btn btn-accent btn-block" disabled data-mark-learn-go>Собрать знак</button>
        </div>
    @endunless
</div>
