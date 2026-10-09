{{-- «Ссылка уже есть» в форме ручной ссылки (`MoneyController::existing`, `ExistingLink`): кто платит, сумма, ТС и что
     с ней — и «Открыть ссылку» вместо второй. Другую сумму берут в её карточке. --}}
@php use App\Support\Money; [$state] = $link->stateLine(); $car = $link->invoice->carLine(); @endphp
<div class="list">
    <div class="row">
        <x-ui.row-icon name="qr" tone="urgent" size="s"/>
        <span class="min-w-0 flex-1">
            <span class="block">Ссылка уже есть</span>
            <span class="row-sub !whitespace-normal">{{ $link->payerName() }}@if ($car), {{ $car }}@endif, <span class="nums">{{ Money::rub($link->amount) }}</span>, {{ $state }}</span>
        </span>
    </div>
</div>
<x-ui.button :href="\App\Http\Admin\MoneyController::linkUrl($link)" block class="mt-3" data-turbo-frame="_top">Открыть ссылку</x-ui.button>
