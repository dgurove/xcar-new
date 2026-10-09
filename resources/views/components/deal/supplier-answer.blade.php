{{-- Ответ поставщика по списку (09.10.2026, владелец: Каркаде отвечает одним ответом на весь список): все сделки вендора,
     что ждут «Поставщик согласовал», строками — у каждой «Ждём | Отдаёт | Отказал». Сохранить — каждая сделка уходит своим
     исходом (`DealController::supplierAnswer`), «Ждём» остаётся на месте. --}}
@props(['deals', 'id'])
<x-ui.sheet :id="$id" title="Ответ поставщика">
    <form method="post" action="/work/deals/supplier-answer" class="flex flex-col gap-4">
        @csrf
        <div class="list">
            @foreach ($deals as $deal)
                @php $offer = $deal->offer; @endphp
                <div class="row flex-col items-stretch gap-2">
                    <span class="flex items-baseline gap-3">
                        <span class="min-w-0 flex-1 break-words">{{ $offer->titleWithYear() }}</span>
                        <span class="nums shrink-0">{{ \App\Support\Money::rub($deal->amount) }}</span>
                    </span>
                    <span class="row-sub mt-0"><span class="nums whitespace-nowrap">{{ $offer->leaseRef() ?? $offer->claim_ref ?? '№'.$offer->number }}</span><span>{{ $deal->buyer?->shortName() }}</span></span>
                    <span class="flex flex-wrap gap-1.5">
                        <label class="choice"><input type="radio" name="answers[{{ $deal->id }}]" value="" checked><span>Ждём</span></label>
                        <label class="choice"><input type="radio" name="answers[{{ $deal->id }}]" value="agreed"><span>Отдаёт</span></label>
                        <label class="choice choice-danger"><input type="radio" name="answers[{{ $deal->id }}]" value="declined"><span>Отказал</span></label>
                    </span>
                </div>
            @endforeach
        </div>
        <div class="sheet-foot"><x-ui.button block>Сохранить</x-ui.button></div>
    </form>
</x-ui.sheet>
