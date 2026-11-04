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
                    <span class="block text-sm {{ $ask->late() ? 'text-danger' : 'text-ink-muted' }}">{{ $ask->late() ? 'не ответил, срок вышел' : 'ждём ответа' }} <span class="nums">{{ \App\Support\Ago::html($ask->due_at->setTimezone('Europe/Moscow'), true, words: false) }}</span></span>
                    {{-- Пока думает — показываем всем (`ShowDealToAll`, 04.11.2026); согласится — снимется само. --}}
                    @if ($ask->deal_id && $offer->showsDealAsOpen())
                        <span class="block text-sm text-accent-text">показываем всем менеджерам до <span class="nums">{{ \App\Support\Ago::html($offer->deal_show_until, true, words: false) }}</span>
                            <form method="post" action="/offers/{{ $offer->number }}/show-all" class="inline" data-turbo-confirm="Убрать машину из предложений у всех менеджеров?" data-turbo-confirm-label="Снять показ">@csrf @method('delete')<button class="underline decoration-dotted underline-offset-4 text-ink-muted">снять</button></form></span>
                    @endif
                </span>
                <x-offer.answer-for :action="'/offers/'.$offer->number.'/reprice-asks/'.$ask->id" :agree-ask="'За менеджера: согласен на '.\App\Support\Money::rub($ask->to_price).'?'"
                    decline-ask="За менеджера: отказался?" :decline-text="$ask->declineMeans()"/>
            </div>
        @endforeach
    </div>
@endif
