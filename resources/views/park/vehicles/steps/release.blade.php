@php use App\Park\{ReleasedTo, PhotoStage}; use App\Support\Money; @endphp
{{-- Шаг «Выдача»: когда, кому, долг; фото при выдаче — свёрнутым блоком (чаще всего выдачу не снимают); подпись.
     Рядом с «Выдать» — «Покупатель отказался»: приехал, посмотрел и не взял. --}}<div class="mt-3 grid grid-cols-2 gap-3">
    <x-ui.field name="released_at" label="Когда" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
    <div class="field">
        <span class="field-label">Кому</span>
        <div class="flex flex-wrap gap-1.5">
            @foreach (ReleasedTo::cases() as $to)
                <label class="choice"><input type="radio" name="to" value="{{ $to->value }}" @checked(old('to', $vehicle->sold_at ? ReleasedTo::Buyer->value : null) === $to->value)><span>{{ $to->label() }}</span></label>
            @endforeach
        </div>
    </div>
    @if ($vehicle->pickup_name || $vehicle->pickup_phone)<div class="col-span-full flex flex-wrap gap-1.5"><span class="tag">{{ $vehicle->pickup_name }}</span>@if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="tag nums">{{ $vehicle->pickup_phone }}</a>@endif</div>@endif
    @php $hold = $debt + $cashDue; @endphp
    @if ($hold > 0 || $vendorDue > 0)
        {{-- Держат выдачу только неоплаченные счета и наличные с покупателя; набежавшее вендору уйдёт счётом. --}}
        <div class="col-span-full flex flex-wrap items-center gap-2">
            @if ($debt > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">не оплачено {{ Money::rub($debt) }}</x-ui.pill>@endif
            @if ($cashDue > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">покупатель {{ Money::rub($cashDue) }}</x-ui.pill><x-ui.check name="cash">Принял наличными {{ Money::rub($cashDue) }}</x-ui.check>@endif
            @if ($vendorDue > 0)<span class="tag nums">вендору {{ Money::rub($vendorDue) }} выставим счётом</span>@endif
            @if ($hold > 0)@if ($debtBlocks)<x-ui.check name="force">Выдать с долгом</x-ui.check>@else<span class="tag">вендору можно выдавать без оплаты</span>@endif @endif
        </div>
    @endif
</div>
<x-park.photos :vehicle="$vehicle" :stage="PhotoStage::Release" nested collapsed edit class="mt-3"/>
<x-ui.card title="Кто получил" nested class="mt-3">
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="signer_name" label="Имя" span="col-span-full"/>
        <x-park.signature/>
    </div>
</x-ui.card>
@if ($byQr ?? false)
    {{-- Выдача по QR: главная кнопка — сканер; код подошёл — «Выдать». Без QR — маленькой серой кнопкой, шторкой с причиной. --}}
    <div class="mt-4" data-controller="qr-release" data-qr-release-check-url-value="/cars/{{ $vehicle->id }}/pass-check" data-qr-release-preset-value="{{ $scannedPass }}">
        <input type="hidden" name="pass" data-qr-release-target="pass">
        <input type="hidden" name="pass_confirm" value="" data-qr-release-target="confirm">
        <div data-qr-release-target="result" hidden></div>
        <div class="mt-3 flex flex-wrap items-center gap-3">
            <button type="button" class="btn btn-accent w-full sm:w-auto" data-qr-release-target="scan" data-action="qr-release#open"><x-ui.icon name="qr" class="size-5"/>Сканировать QR</button>
            <x-ui.button id="act-submit" class="w-full sm:w-auto" data-qr-release-target="submit" hidden>Выдать</x-ui.button>
            @if ($vehicle->sold_at && $canManage)
                <button type="button" class="btn btn-quiet w-full sm:w-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="refusal:open">Покупатель отказался</button>
            @endif
        </div>
        @if ($canManage)<button type="button" class="mt-3 text-sm text-ink-dim underline-offset-4 hover:underline" data-controller="emit" data-action="emit#send" data-emit-event-param="without-qr:open">Выдать без QR</button>@endif
        @error('pass')<p class="field-error mt-2">{{ $message }}</p>@enderror
    </div>
@else
<div class="mt-4 flex flex-wrap items-center gap-3">
    <x-ui.button id="act-submit" class="w-full sm:w-auto">Выдать</x-ui.button>
    @if ($vehicle->sold_at && $canManage)
        <button type="button" class="btn btn-quiet w-full sm:w-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="refusal:open">Покупатель отказался</button>
    @endif
</div>
@endif
