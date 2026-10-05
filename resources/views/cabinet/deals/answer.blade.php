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
<form method="post" action="/deals/{{ $deal->id }}/reply" class="mt-5 flex flex-col gap-4">
    @csrf
    @if ($requirement->asks === Asks::Fields)
        @foreach ($requirement->fields as $field)
            <x-route.field :field="$field" :name="'fields['.$field['key'].']'"/>
        @endforeach
    @endif
    @if ($errors->has('exit'))<p class="field-error">{{ $errors->first('exit') }}</p>@endif
    {{-- Один исход — во всю ширину, два — в ряд одной ширины, как ответы в диалоге приложения; больше — переносом. --}}
    <div @class(['gap-3', 'grid' => $exits->count() <= 2, 'grid-cols-2' => $exits->count() === 2, 'flex flex-wrap' => $exits->count() > 2])>
        @foreach ($exits as $exit)
            <x-ui.button name="exit" :value="$exit->id" :variant="$loop->first ? 'primary' : 'secondary'" :data-turbo-confirm="$exit->confirm" class="min-w-0 px-4">{{ $exit->label }}</x-ui.button>
        @endforeach
    </div>
</form>
