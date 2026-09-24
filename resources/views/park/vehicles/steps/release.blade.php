@php
    use App\Park\PhotoStage; use App\Support\Money;
    $qr = $byQr ?? false;
    $hold = $debt + $cashDue;
    $who = ($pass ?? null)?->name ?? $vehicle->pickup_name;
@endphp
{{-- Шаг «Выдача». По QR первым — «Сканировать QR» (сначала убедиться, что приехал покупатель), его место занимает
     итог скана. Дальше одна плашка: когда (по умолчанию сейчас) и деньги строками. «Кому» и «Кто получил» не
     спрашиваются: получатель — из пропуска или письма о продаже (`Release` вписывает его в акт), подпись ставят ручкой
     на бумаге. Фото при выдаче — свёрнутым блоком. Внизу «Выдать» (по QR — только когда код подошёл), «Покупатель
     отказался» и маленькой серой «Выдать без QR» — шторкой с причиной. --}}
<div @if ($qr) data-controller="qr-release" data-qr-release-check-url-value="/cars/{{ $vehicle->id }}/pass-check" data-qr-release-preset-value="{{ $scannedPass }}" @endif>
@if ($qr)
    <input type="hidden" name="pass" data-qr-release-target="pass">
    <input type="hidden" name="pass_confirm" value="" data-qr-release-target="confirm">
    <button type="button" class="qr-scan-tile mt-3" data-qr-release-target="scan" data-action="qr-release#open"><span class="qr-scan-tile-icon"><x-ui.icon name="qr" class="size-6"/></span>Сканировать QR</button>
    <div data-qr-release-target="result" hidden></div>
    @error('pass')<p class="field-error mt-2">{{ $message }}</p>@enderror
@endif
<div class="list release-list mt-3">
    <label class="pass-row">
        <span class="text-ink-muted">Когда</span>
        <input type="datetime-local" name="released_at" value="{{ old('released_at', now()->format('Y-m-d\TH:i')) }}" class="release-when">
    </label>
    @error('released_at')<p class="field-error px-4 pb-2">{{ $message }}</p>@enderror
    @if (! $qr && $who)
        <div class="pass-row">
            <span class="min-w-0"><span class="block truncate">{{ $who }}</span>@if ($vehicle->pickup_phone)<span class="nums block text-sm text-ink-muted">{{ $vehicle->pickup_phone }}</span>@endif</span>
            @if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="btn btn-round btn-quiet shrink-0" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
        </div>
    @endif
    {{-- Держат выдачу только неоплаченные счета и наличные с покупателя; набежавшее вендору уйдёт счётом. --}}
    @if ($debt > 0)
        <div class="pass-row"><span class="text-danger">Не оплачено</span><span class="nums text-danger">{{ Money::rub($debt) }}</span></div>
    @endif
    @if ($cashDue > 0)
        <label class="pass-row cursor-pointer"><span class="min-w-0"><span class="block">Наличными с покупателя</span><span class="nums block text-sm text-danger">{{ Money::rub($cashDue) }}</span></span><input type="checkbox" switch name="cash" value="1" @checked(old('cash')) class="switch shrink-0" aria-label="Принял наличными"></label>
    @endif
    @if ($hold > 0)
        @if ($debtBlocks)
            <label class="pass-row cursor-pointer"><span>Выдать с долгом</span><input type="checkbox" switch name="force" value="1" @checked(old('force')) class="switch shrink-0"></label>
        @else
            <div class="pass-row"><span class="text-ink-muted">Вендору можно выдавать без оплаты</span></div>
        @endif
    @endif
    @if ($vendorDue > 0)
        <div class="pass-row"><span class="text-ink-muted">Вендору выставим счётом</span><span class="nums text-ink-muted">{{ Money::rub($vendorDue) }}</span></div>
    @endif
</div>
<x-park.photos :vehicle="$vehicle" :stage="PhotoStage::Release" nested collapsed edit class="mt-3"/>
<div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
    <x-ui.button id="act-submit" class="w-full sm:w-auto" :data-qr-release-target="$qr ? 'submit' : null" :hidden="$qr">Выдать</x-ui.button>
    @if ($vehicle->sold_at && $canManage)
        <button type="button" class="btn btn-quiet w-full sm:w-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="refusal:open">Покупатель отказался</button>
    @endif
    @if ($qr && $canManage)
        <button type="button" class="btn btn-s btn-quiet self-center sm:ml-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="without-qr:open">Выдать без QR</button>
    @endif
</div>
</div>
