{{-- Ответ на просьбу шага: файлы с «Приложить» (просит документ), поля (просит поля), исходы кнопками. Одно на обычную
     задачу и на последний пункт чек-листа договора («Подписанный договор»). --}}
@php use App\Workflow\Asks; @endphp
@if ($requirement->asks === Asks::Document)
    {{-- «Приложить» одним документом (`cabinet.deals.attach`, 24.11.2026): шаг оплаты платёжное поручение закрывает само
         (`DealController::uploadDoc`) — после него страница перечитывается. --}}
    <div class="mt-5">
        @include('cabinet.deals.files', ['requirement' => $requirement])
        @include('cabinet.deals.attach')
        @if ($errors->has('files'))<p class="field-error mt-2">{{ $errors->first('files') }}</p>@endif
    </div>
@endif
@include('cabinet.deals.exits')
