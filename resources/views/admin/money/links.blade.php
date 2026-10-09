{{-- «По ссылкам» строками (меню вида; по умолчанию — таблица `admin/money/links-table`): кто платит, под ним ТС с номером,
     откуда и что со ссылкой, справа сумма. Свежие сверху, отменённые — чипом, бледными. --}}
@php
    use App\Http\Admin\MoneyLinks as L; use App\Support\Money; use App\Billing\Acquiring\PayLinkState;
    $items = $links instanceof \Illuminate\Support\Collection ? $links : collect($links->items());
@endphp
@if (! ($titled && $items->isEmpty()))
<section class="mt-4" data-search-group>
    @if ($titled)<h2 class="list-head">{{ \App\Http\Admin\MoneyController::TABS['links'] }}</h2>@endif
    @if ($items->isNotEmpty())
        <div class="list">
            @foreach ($items as $l)
                @php [$car, $number] = L::car($l); [$state, $tone] = L::state($l); @endphp
                <x-money.feed-row :key="$l->id" :with="['tab' => 'links']" :title="$l->payerName()" :amount="Money::rub($l->amount)" :tone="$l->state === PayLinkState::Paid ? 'text-open' : null"
                    @class(['opacity-50' => $l->state === PayLinkState::Canceled])>
                    @if ($car)<span>{{ $car }}@if ($number), № {{ $number }}@endif</span>@endif
                    <span>{{ L::who($l) }}</span>
                    <span class="{{ $tone }}">{{ $state }}</span>
                </x-money.feed-row>
            @endforeach
        </div>
        @if (! $links instanceof \Illuminate\Support\Collection && $links->hasPages())<div class="mt-8"><x-ui.pager :of="$links"/></div>@endif
    @endif
</section>
@endif
