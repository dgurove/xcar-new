{{-- Деньги по сделкам: заявки об оплате первыми, потом вознаграждения к выплате, счета. Строки, без плиток. --}}
<x-ui.shell title="Деньги" :heading="false">
    <x-admin.work-titles current="money" :count="$invoices->total()"/>
    <x-ui.toolbar class="mt-5" :pills="\App\Http\Admin\MoneyController::PRESETS" :pill="$preset" pill-param="preset" :counts="$counts" :tones="['claims' => !empty($counts['claims']) ? 'pill-urgent' : '']" name="money"/>

    @if ($invoices->isEmpty())
        <x-ui.empty class="mt-6">{{ match ($preset) { 'claims' => 'Никто об оплате не сообщал', 'payouts' => 'Выплачивать нечего', default => 'Счетов нет' } }}</x-ui.empty>
    @else
        <div class="mt-6 flex flex-col gap-2">
            @foreach ($invoices as $invoice)
                @include('admin.money.row', ['invoice' => $invoice])
            @endforeach
        </div>
        <div class="mt-8"><x-ui.pager :of="$invoices"/></div>
    @endif
</x-ui.shell>
