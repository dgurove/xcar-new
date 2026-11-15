{{-- Ответ на просьбу шага: файлы с «Приложить» (просит документ), поля (просит поля), исходы кнопками. Одно на обычную
     задачу и на последний пункт чек-листа договора («Подписанный договор»). --}}
@php use App\Workflow\Asks; @endphp
@if ($requirement->asks === Asks::Document)
    {{-- Шаг оплаты: платёжка сама закрывает шаг (`DealController::upload`) — после загрузки страница перечитывается. --}}
    <div class="mt-5" data-controller="photos" data-photos-url-value="/deals/{{ $deal->id }}/files" @if ($requirement->stage?->isPayStep() && $deal->soleMove($requirement->stage)) data-photos-reload-value="true" @endif>
        <input type="file" accept="image/*,.pdf,.heic" multiple class="sr-only" tabindex="-1" data-photos-target="input" data-action="change->photos#upload">
        @include('cabinet.deals.files', ['requirement' => $requirement])
        <div hidden data-photos-target="progress" class="my-2">
            <div class="mb-1 text-sm text-ink-muted" data-label></div>
            <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
        </div>
        @include('cabinet.deals.attach')
        @if ($errors->has('files'))<p class="field-error mt-2">{{ $errors->first('files') }}</p>@endif
    </div>
@endif
@include('cabinet.deals.exits')
