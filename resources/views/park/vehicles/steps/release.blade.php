@php
    use App\Park\PhotoStage; use App\Support\Money;
    $qr = $byQr ?? false;
    $hold = $releaseDebt + $cashDue;
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
    <div class="row justify-between">
        <label for="f-released_at" class="text-ink-muted">Когда</label>
        <x-ui.date name="released_at" :value="old('released_at', now()->format('Y-m-d\TH:i'))" time now :clearable="false" class="release-when"/>
    </div>
    @error('released_at')<p class="field-error px-4 pb-2">{{ $message }}</p>@enderror
    {{-- Кто заберёт — из письма о продаже; не вписан — «Указать» (шторка «Покупатель»), вписан — имя и звонок. --}}
    @if (! $qr && $who)
        <div class="row justify-between">
            <span class="min-w-0"><span class="block truncate">{{ $who }}</span>@if ($vehicle->pickup_phone)<span class="nums block text-sm text-ink-muted">{{ $vehicle->pickup_phone }}</span>@endif</span>
            @if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="btn btn-round btn-quiet shrink-0" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
        </div>
    @elseif (! $qr && $canManage)
        <div class="row justify-between">
            <span class="text-ink-muted">Кто заберёт</span>
            <button type="button" class="btn btn-s btn-quiet shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="sold:open">Указать</button>
        </div>
    @endif
    {{-- Держат выдачу только неоплаченное покупателем и наличные с него; страховая сторона платит счётом. --}}
    @if ($releaseDebt > 0)
        <div class="row justify-between"><span class="text-danger">Не оплачено</span><span class="nums text-danger">{{ Money::rub($releaseDebt) }}</span></div>
    @endif
    @if ($cashDue > 0)
        <label class="row justify-between cursor-pointer"><span class="min-w-0"><span class="block">Наличными с покупателя</span><span class="nums block text-sm text-danger">{{ Money::rub($cashDue) }}</span></span><input type="checkbox" switch name="cash" value="1" @checked(old('cash')) class="switch shrink-0" aria-label="Принял наличными"></label>
    @endif
    @if ($hold > 0)
        @if ($debtBlocks && $canManage)
            <label class="row justify-between cursor-pointer"><span>Выдать с долгом</span><input type="checkbox" switch name="force" value="1" @checked(old('force')) class="switch shrink-0"></label>
        @elseif (! $debtBlocks)
            <div class="row justify-between"><span class="text-ink-muted">Вендору можно выдавать без оплаты</span></div>
        @endif
    @endif
    @if ($vendorDue > 0)
        <div class="row justify-between"><span class="text-ink-muted">Вендору выставим счётом</span><span class="nums text-ink-muted">{{ Money::rub($vendorDue) }}</span></div>
    @endif
</div>
<x-park.photos :vehicle="$vehicle" :stage="PhotoStage::Release" nested collapsed edit class="mt-3"/>
<div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
    <x-ui.button id="act-submit" class="w-full sm:w-auto" :data-qr-release-target="$qr ? 'submit' : null" :hidden="$qr">Выдать</x-ui.button>
    @if ($vehicle->sold_at && $canManage)
        {{-- Редкий исход — маленькой серой, как «Выдать без QR», а не второй кнопкой ростом с «Выдать» (10.10.2026). --}}
        <button type="button" class="btn btn-s btn-quiet self-center sm:ml-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="refusal:open">Покупатель отказался</button>
    @endif
    @if ($qr && $canManage)
        <button type="button" class="btn btn-s btn-quiet self-center sm:ml-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="without-qr:open">Выдать без QR</button>
    @endif
</div>
</div>
