{{-- Ответ на просьбу шага: файлы с «Приложить» (просит документ), поля (просит поля), исходы кнопками. Одно на обычную
     задачу и на последний пункт чек-листа договора («Подписанный договор»). --}}
@php use App\Workflow\Asks; @endphp
@if ($requirement->asks === Asks::Document)
    <div class="mt-5" data-controller="photos" data-photos-url-value="/deals/{{ $deal->id }}/files">
        <input type="file" accept="image/*,.pdf,.heic" multiple hidden data-photos-target="input" data-action="change->photos#upload">
        @include('cabinet.deals.files', ['requirement' => $requirement])
        <div hidden data-photos-target="progress" class="my-2">
            <div class="mb-1 text-sm text-ink-muted" data-label></div>
            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
        </div>
        <x-ui.button type="button" variant="secondary" size="s" data-action="photos#pick"><x-ui.icon name="camera" class="size-4"/> Приложить</x-ui.button>
        @if ($errors->has('files'))<p class="field-error mt-2">{{ $errors->first('files') }}</p>@endif
    </div>
@endif
@include('cabinet.deals.exits')
