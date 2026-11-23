{{-- «⋯» раскрытого письма — одно меню на все места (17.11.2026, владелец: «одна цепочка, везде одинаково»): справа во
     «Входящих», в окне писем предложения, сделки, ТС, ветки. Нижнего ряда под лентой больше нет — что было там, здесь:
     - «В архив» (ветка письма; справа во «Входящих» — к следующему письму, фрейм detail), «Не прочитано» (входящее);
     - «Привязать / Перепривязать» (форма привязки под шапкой письма, `unhide`), «Выбрать / Сменить вендора», «Страница ветки»;
     - по идущей сделке, у письма поставщика (из `x-mail.reply-button`, 28.10–16.11.2026): «Показать менеджеру» (шторка
       `admin.deals.reply-review-sheet`, событием — «⋯» сначала закрывается), «Ответ не нужен» (ответ ждёт разбора или ветка
       ждёт ответа), «Поставщик продал другому» — опасное, последним; пока поставщик не подтвердил оплату.
     dismiss — письмо ждёт ответа в другой ветке того же дела (окно писем ТС). --}}
@props(['message', 'base', 'context' => 'window', 'park' => false, 'dismiss' => false])
@php
    $m = $message;
    $thread = $m->thread;
    $linked = $park ? $thread?->vehicle : $thread?->offer;
    $incoming = ! $m->isOurs() || $m->isForwardedByStaff();
    $pick = $thread ? \App\Mail\VendorPick::of(auth()->user(), $park ? \App\Mail\Scope::Park : \App\Mail\Scope::Offers, $base, $thread, $thread->messages, $thread->candidate) : null;
    $deal = ! $park && auth()->user()?->canEditDeals() && $incoming ? $thread?->offer?->deal : null;
    $deal = $deal?->isActive() ? $deal : null;
    $replied = $deal && $thread->offer->position(\App\Workflow\Track::Sale)?->letterStatus() === 'replied';
    $noReply = ($thread?->needs_reply_at || $replied || $dismiss) && \App\Mail\Threads::canAnswer(auth()->user());
    $declined = $deal && ! $deal->prepaid_at && auth()->user()->canEditDeals();
    $said = $declined ? trim(\Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', (string) $m->shownText()), 60, '…')) : '';
    $disclose = $deal?->buyer_id ? 'disclose-letter-'.$m->id : null;
@endphp
@if ($thread)
<x-ui.more :id="'letter-menu-'.$m->id">
    <div class="menu-group">
        <form method="post" action="{{ $base }}/{{ $thread->id }}/archive" data-turbo-frame="{{ $context === 'pane' ? 'detail' : '_top' }}" class="contents">@csrf<button class="menu-item w-full" role="menuitem">{{ $thread->archived_at ? 'Вернуть из архива' : 'В архив' }}</button></form>
        @if ($incoming)
            <form method="post" action="{{ $base }}/messages/{{ $m->id }}/seen" data-turbo-frame="_top" class="contents">@csrf<input type="hidden" name="read" value="0"><button class="menu-item w-full" role="menuitem">Не прочитано</button></form>
        @endif
    </div>
    <div class="menu-group">
        <button type="button" class="menu-item w-full" role="menuitem" data-action="unhide#show">{{ $linked ? 'Перепривязать' : ($park ? 'Привязать к ТС' : 'Привязать к предложению') }}</button>
        @if ($pick)<button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send"
            data-emit-event-param="vendor-pick:open" data-emit-url-param="{{ $pick['url'] }}" data-emit-sender-param="{{ $pick['sender'] }}" data-emit-name-param="{{ $pick['name'] }}" data-emit-domain-param="{{ $pick['domain'] }}">{{ $thread->vendor ? 'Сменить вендора' : 'Выбрать вендора' }}</button>@endif
        @unless ($park)<a href="{{ $base }}/{{ $thread->id }}" class="menu-item" role="menuitem" data-turbo-frame="_top">Страница ветки</a>@endunless
    </div>
    @if ($disclose || $noReply || $declined)
        <div class="menu-group">
            @if ($disclose)<button type="button" class="menu-item w-full" role="menuitem" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $disclose }}:open">Показать менеджеру</button>@endif
            @if ($noReply)
                {{-- Вопрос решили иначе — разобрано, письмо не в архив (09.10.2026); по сделке — ждём поставщика дальше (28.10.2026). --}}
                <form method="post" action="{{ $base }}/{{ $thread->id }}/answered" data-turbo-frame="_top" class="contents">@csrf<button class="menu-item w-full" role="menuitem">Ответ не нужен</button></form>
            @endif
            @if ($declined)
                <form method="post" action="/work/deals/{{ $deal->id }}/declined" data-turbo-frame="_top" class="contents"
                      data-turbo-confirm="Поставщик продал другому?" data-turbo-confirm-text="Сделка закроется: отказ поставщика, предложение — в архив с пометкой, менеджеру придёт уведомление" data-turbo-confirm-label="Закрыть сделку">
                    @csrf
                    <input type="hidden" name="note" value="Поставщик продал другому{{ $said !== '' ? ': «'.$said.'»' : '' }}">
                    <input type="hidden" name="pickup" value="{{ $deal->offer?->vendor?->pickup_on_arrival ? 'keep' : 'drop' }}">
                    <button class="menu-item w-full text-danger" role="menuitem">Поставщик продал другому</button>
                </form>
            @endif
        </div>
    @endif
</x-ui.more>
@if ($disclose)
    {{-- «Показать менеджеру» это письмо — та же шторка, что у строки письма в шаге, с отмеченным текстом письма. --}}
    @php $shown = collect([$m]); @endphp
    <div class="contents" data-controller="sheet" data-action="{{ $disclose }}:open@window->sheet#open">
        @include('admin.deals.reply-review-sheet', ['id' => $disclose, 'deal' => $deal, 'letters' => $shown, 'proposals' => \App\Offers\Disclosures::proposals($deal, $shown, $m->id),
            'step' => \App\Offers\Actions\Disclose::stepExit($deal, $shown), 'picked' => [], 'message' => $m, 'top' => true])
    </div>
@endif
@endif
