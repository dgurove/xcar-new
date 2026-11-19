{{-- Контрпредложение менеджеру (26.10.2026): цена админа крупно, своя — строкой ниже, комментарий цитатой. «Согласен» —
     сразу сделка (переспрашивает: машина закрепится по этой цене), «Отказаться» — подтверждение снимается (19.11.2026, владелец). Стоит над формой
     подтверждения и над «Ваше подтверждение», пока контр живой (`Bid::hasPendingCounter`). --}}
@props(['bid'])
@php use App\Support\Money; @endphp
@if ($bid?->hasPendingCounter())
    <x-offer.answer-card {{ $attributes }} caption="Контрпредложение" :sum="$bid->counter_amount" :quote="$bid->counter_comment"
        :sub="'ваша цена '.Money::rub($bid->amount).', на '.Money::rub($bid->counter_amount - $bid->amount).' выше'"
        :action="'/confirmations/'.$bid->id.'/counter/answer'" :agree="'Согласен за '.Money::rub($bid->counter_amount)"
        :agree-ask="'Согласиться на '.Money::rub($bid->counter_amount).'?'" agree-text="ТС закрепится за вами по этой цене"
        decline-ask="Отказаться от контрпредложения?" :decline-text="\App\Offers\Warnings::BID_OFF" error="counter">
        {{-- «Напомнить через…» (19.11.2026, владелец: «соглашается, напомнить позже или отказывается — такая же схема, как при
             вопросе „Берёте в работу?“»): 1–6 часов, в срок контрпредложение придёт снова (`RemindCounter`). --}}
        <x-slot:remind>
            <div data-controller="sheet" class="contents">
                <button type="button" class="btn btn-quiet min-w-0 flex-1" data-action="sheet#open"><span class="truncate">Напомнить через…</span></button>
                <x-ui.sheet :id="'counter-remind-'.$bid->id" title="Напомнить через" anchor>
                    <x-deal.remind-list :action="'/confirmations/'.$bid->id.'/counter/remind'" :remind-at="$bid->counter_remind_at"/>
                </x-ui.sheet>
            </div>
        </x-slot:remind>
    </x-offer.answer-card>
@endif
