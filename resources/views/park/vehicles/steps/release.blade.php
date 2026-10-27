@php
    use App\Park\PhotoStage; use App\Support\Money;
    $qr = $byQr ?? false;
    $hold = $releaseDebt + $cashDue;
    $who = ($pass ?? null)?->name ?? $vehicle->pickup_name;
    // В окне действия (`park/act/release`) шторки свои — события с приставкой, чтобы не открыть заодно шторки дела под окном.
    $win = $window ?? false;
    $ev = $win ? 'act-' : '';
    // Приёмщик (27.10.2026) выдаёт сам — QR, без QR, отказ, «кто заберёт», — а из денег видит только наличные с покупателя.
    $handles ??= $canManage;
    $money ??= $canManage;
@endphp
{{-- Шаг «Выдача». По QR первым — «Сканировать QR» (сначала убедиться, что приехал покупатель), его место занимает
     итог скана. Дальше одна плашка: когда (по умолчанию сейчас) и деньги строками. «Кому» и «Кто получил» не
     спрашиваются: получатель — из пропуска или письма о продаже (`Release` вписывает его в акт), подпись ставят ручкой
     на бумаге. Фото при выдаче — свёрнутым блоком. Внизу «Выдать» (по QR — только когда код подошёл), «Покупатель
     отказался» и маленькой серой «Выдать без QR» — шторкой с причиной. --}}
<div @class(['act-fill' => $win]) @if ($qr) data-controller="qr-release" data-qr-release-check-url-value="/cars/{{ $vehicle->id }}/pass-check" data-qr-release-preset-value="{{ $scannedPass }}" @endif>
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
    {{-- Кто заберёт — из письма о продаже; не вписан — «Указать» (шторка «Покупатель»), вписан — имя и звонок. По сделке XCar
         (18.10.2026) — получатель из сделки и над сканером: кого ждать и когда, менеджер сделки; сверяют по QR. --}}
    @if (($pass ?? null)?->isDeal() && $pass->isLive())
        <div class="row justify-between">
            <span class="min-w-0">
                <span class="block truncate">{{ $pass->name }}</span>
                <span class="block text-sm text-ink-muted">{{ implode(', ', array_filter(['по сделке XCar', ($m = $pass->deal?->buyer) && $m->name !== $pass->name ? 'менеджер '.$m->shortName() : null, ($w = $pass->whenLabel('j M')) ? 'приедет '.$w : null])) }}</span>
            </span>
            @if ($pass->phone)<a href="tel:+{{ \App\Support\Phone::normalize($pass->phone) }}" class="btn btn-round btn-quiet shrink-0" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
        </div>
    @elseif (! $qr && $who)
        <div class="row justify-between">
            <span class="min-w-0"><span class="block truncate">{{ $who }}</span>@if ($vehicle->pickup_phone)<span class="nums block text-sm text-ink-muted">{{ $vehicle->pickup_phone }}</span>@endif</span>
            @if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="btn btn-round btn-quiet shrink-0" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
        </div>
    @elseif (! $qr && $handles)
        <div class="row justify-between">
            <span class="text-ink-muted">Кто заберёт</span>
            <button type="button" class="btn btn-s btn-quiet shrink-0" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $ev }}sold:open">Указать</button>
        </div>
    @endif
    {{-- Держат выдачу только неоплаченное покупателем и наличные с него; страховая сторона платит счётом. Приёмщику —
         только «получить наличными N ₽» и «Получил»; выставленный и не оплаченный счёт — словами, без суммы и обхода. --}}
    @if ($releaseDebt > 0 && $money)
        <div class="row justify-between"><span class="text-danger">Не оплачено</span><span class="nums text-danger">{{ Money::rub($releaseDebt) }}</span></div>
    @elseif ($releaseDebt > 0 && $debtBlocks)
        <div class="row"><span class="text-danger">Выдача после оплаты счёта — позвоните управляющему</span></div>
    @endif
    @if ($cashDue > 0)
        <label class="row justify-between cursor-pointer"><span class="min-w-0"><span class="block">Получить наличными с покупателя</span><span class="nums block text-sm text-danger">{{ Money::rub($cashDue) }}</span></span><input type="checkbox" switch name="cash" value="1" @checked(old('cash')) class="switch shrink-0" aria-label="Получил наличными"></label>
    @endif
    @if ($hold > 0 && $money)
        @if ($debtBlocks && $canManage)
            <label class="row justify-between cursor-pointer"><span>Выдать с долгом</span><input type="checkbox" switch name="force" value="1" @checked(old('force')) class="switch shrink-0"></label>
        @elseif (! $debtBlocks)
            <div class="row justify-between"><span class="text-ink-muted">Вендору можно выдавать без оплаты</span></div>
        @endif
    @endif
    @if ($vendorDue > 0 && $money)
        <div class="row justify-between"><span class="text-ink-muted">Вендору выставим счётом</span><span class="nums text-ink-muted">{{ Money::rub($vendorDue) }}</span></div>
    @endif
</div>
<x-park.photos :vehicle="$vehicle" :stage="PhotoStage::Release" nested :collapsed="! $win" edit class="mt-3"/>
<div class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center">
    @if ($vehicle->sold_at && $handles)
        {{-- Редкий исход — маленькой серой, как «Выдать без QR», а не второй кнопкой ростом с «Выдать» (10.10.2026). --}}
        <button type="button" class="btn btn-s btn-quiet self-center sm:ml-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $ev }}refusal:open">Покупатель отказался</button>
    @endif
    @if ($qr && $handles)
        <button type="button" class="btn btn-s btn-quiet self-center sm:ml-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $ev }}without-qr:open">Выдать без QR</button>
    @endif
</div>
{{-- Окно действия: кнопки подвалом листа; по QR — появляются, когда код подошёл. --}}
@if ($win)
    <div class="sheet-foot flex flex-col items-center gap-1">
        @if ($letter ?? false)
            <button type="submit" name="then" value="letter" class="btn btn-accent w-full" @if ($qr) data-qr-release-target="submit" hidden @endif>Выдать и сформировать письмо</button>
            <button type="submit" name="then" value="none" class="act-quiet" @if ($qr) data-qr-release-target="submit" hidden @endif>Выдать без письма</button>
        @else
            <button type="submit" class="btn btn-accent w-full" @if ($qr) data-qr-release-target="submit" hidden @endif>Выдать</button>
        @endif
    </div>
@endif
</div>
