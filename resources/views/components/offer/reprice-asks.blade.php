{{-- Ждём ответа менеджера про выросшую закупочную (28.10.2026, `RepriceAsk`) — в CRM строками под плашками окошка, редактора
     и сделки: кто, что выросло, до когда (вышел срок — красным). Он сказал устно — «Согласен» / «Отказался» за него (тот же
     `AnswerReprice`; отказ отменяет сделку — с переспросом). Только админу CRM. --}}
@props(['offer'])
@php
    $asks = auth()->user()?->canManageCrm()
        ? \App\Offers\RepriceAsk::pending()->where('offer_id', $offer->id)->with(['user', 'deal', 'bid'])->orderBy('id')->get()->filter->alive()
        : collect();
@endphp
@if ($asks->isNotEmpty())
    <div {{ $attributes->class('list') }}>
        @foreach ($asks as $ask)
            <div class="row flex-wrap items-center gap-x-3 gap-y-2">
                <span class="min-w-0 flex-1">
                    <span class="block">{{ $ask->user->shortName() }} — {{ $ask->deal_id ? 'сделка' : 'подтверждение' }}, закупочная <span class="nums">{{ \App\Support\Money::nums($ask->from_price) }} → {{ \App\Support\Money::rub($ask->to_price) }}</span></span>
                    <span class="block text-sm {{ $ask->late() ? 'text-danger' : 'text-ink-muted' }}">{{ $ask->late() ? 'не ответил, срок вышел' : 'ждём ответа' }} <span class="nums">{{ $ask->due_at->setTimezone('Europe/Moscow')->translatedFormat('j M, H:i') }}</span></span>
                </span>
                <x-offer.answer-for :action="'/offers/'.$offer->number.'/reprice-asks/'.$ask->id" :agree-ask="'За менеджера: согласен на '.\App\Support\Money::rub($ask->to_price).'?'"
                    decline-ask="За менеджера: отказался?" :decline-text="$ask->declineMeans()"/>
            </div>
        @endforeach
    </div>
@endif
