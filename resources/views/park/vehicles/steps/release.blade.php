@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «Выдача»: когда, кому, документ, соответствует ли, долг; фото при выдаче рядом с «При приёме»; подпись. --}}<div class="mt-3 grid grid-cols-2 gap-3">
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
    <x-ui.field name="note" label="По какому документу" span="col-span-full" :value="$vehicle->pickup_note"/>
    {{-- Получатель подписывает: соответствует акту приёма или нет; не соответствует и не забрал — акт с отказом, ТС остаётся.
         Поле зовётся fits, не matches: имя поля формы перекрыло бы form.matches(), и Stimulus падает. --}}
    <div class="field col-span-full">
        <span class="field-label">Получатель осмотрел</span>
        <div class="flex flex-wrap gap-1.5">
            <label class="choice"><input type="radio" name="fits" value="1" data-action="reveal#pick" @checked(old('fits', '1') === '1')><span>Соответствует</span></label>
            <label class="choice"><input type="radio" name="fits" value="0" data-action="reveal#pick" @checked(old('fits') === '0')><span>Не соответствует</span></label>
        </div>
    </div>
    <div class="col-span-full grid grid-cols-2 gap-3" data-reveal-target="pane" data-reveal-key="0" hidden>
        <x-ui.field name="mismatch_note" label="Что не так" type="textarea" span="col-span-full" disabled/>
        <div class="field col-span-full">
            <div class="flex flex-wrap gap-1.5">
                <label class="choice"><input type="radio" name="refused" value="0" disabled @checked(old('refused', '0') === '0')><span>Забрал</span></label>
                <label class="choice"><input type="radio" name="refused" value="1" disabled @checked(old('refused') === '1')><span>Не забрал</span></label>
            </div>
        </div>
    </div>
    @if ($debt > 0)
        <div class="col-span-full flex flex-wrap items-center gap-2">
            @if ($debt - $buyerDebt > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">{{ $buyerDebt > 0 ? 'вендор ' : 'долг ' }}{{ Money::rub($debt - $buyerDebt) }}</x-ui.pill>@endif
            @if ($buyerDebt > 0)<x-ui.pill tone="danger" class="!min-h-0 !py-1 text-xs nums">покупатель {{ Money::rub($buyerDebt) }}</x-ui.pill><x-ui.check name="cash">Принял наличными {{ Money::rub($buyerDebt) }}</x-ui.check>@endif
            @if ($debtBlocks)<x-ui.check name="force">Выдать с долгом</x-ui.check>@else<span class="tag">вендору можно выдавать без оплаты</span>@endif
        </div>
    @endif
</div>
@if ($intakePhotos->isNotEmpty())
    <x-ui.card title="При приёме" :count="$intakePhotos->count()" nested class="mt-3" data-controller="photos" data-photos-readonly-value="true">
        <x-ui.photos :photos="$intakePhotos" readonly grid :hide="false" :main="false" id="intake-gallery"/>
    </x-ui.card>
@endif
<x-ui.card title="При выдаче" nested class="mt-3" data-controller="photos" data-photos-url-value="/cars/{{ $vehicle->id }}/media">
    <input type="file" accept="image/*,.heic,.heif" capture="environment" multiple hidden data-photos-target="input" data-action="change->photos#upload">
    <div hidden data-photos-target="progress" class="mb-3">
        <div class="mb-1 text-sm text-ink-muted" data-label></div>
        <div class="h-1.5 overflow-hidden rounded-full bg-surface-3"><div class="h-full bg-accent transition-[width]" data-bar style="width:0"></div></div>
    </div>
    <div id="photo-slots"><x-park.photo-slots :vehicle="$vehicle" stage="release" :slots="$slots"/></div>
</x-ui.card>
<x-ui.card title="Кто получил" nested class="mt-3">
    <div class="grid grid-cols-2 gap-3">
        <x-ui.field name="signer_name" label="Имя" span="col-span-full"/>
        <x-park.signature/>
    </div>
</x-ui.card>
<div class="mt-4"><x-ui.button id="act-submit" class="w-full sm:w-auto">Выдать</x-ui.button></div>
