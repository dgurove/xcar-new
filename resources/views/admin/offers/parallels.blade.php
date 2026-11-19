{{-- «Параллельно» — сделки, поручённые параллельно (07.11.2026, `Offers\Parallels`): у каждой — менеджер, его цена, где он у
     себя (его ход, «реквизиты есть»), его покупатель с данными (`x-deal.buyer`: посмотреть, скан PDF, заполнить за него) и
     «Взять в работу» — переключить сделку на него (`TakeParallel`; что будет — в подтверждении), «Снять» — закрыть одну.
     Стоит в карточке «Сделка» между принятым и резервом, а без идущей (главную отменили) — над подтверждениями. --}}
@php
    use App\Support\Money;
    use App\Workflow\Asks;
    $main ??= null;
    $chats ??= \App\Chats\Chat::byManager($offer);
    $parallels = \App\Offers\Parallels::of($offer)->each(fn ($p) => $p->setRelation('offer', $offer));
    $main?->setRelation('offer', $offer);
    // Почему нельзя переключить — общее для всех параллельных: один раз на блок.
    $locked = $main && $parallels->isNotEmpty() ? \App\Offers\Parallels::locked($main) : null;
@endphp
@if ($parallels->isNotEmpty())
    {{-- Строкой подтверждения (19.11.2026, вариант В): справа чат и «Взять», «Снять» — в шторке строки. --}}
    <div>
    @unless ($bare ?? false)<div id="parallel" class="list-cap scroll-mt-24 !px-1">Параллельно <span class="nums">{{ $parallels->count() }}</span></div>@endunless
    <div class="list">
        @foreach ($parallels as $p)
            @php
                $stage = $p->shadowStage;
                $req = $p->openRequirement;
                $ready = \App\Offers\Parallels::ready($p);
                [$word, $tone] = match (true) {
                    (bool) $req => [($req->asks === Asks::Buyer ? 'ждём реквизиты' : 'его ход: «'.$req->title.'»'), 'urgent'],
                    $ready && (bool) $stage?->isLetterStep() => ['реквизиты есть', 'open'],
                    default => [mb_strtolower($stage?->name ?? 'ждёт'), 'closed'],
                };
                $take = \App\Offers\Parallels::takeAsk($p, $main, $locked);
            @endphp
            {{-- Строкой подтверждения (19.11.2026, вариант 3): справа цена; нажатие — шторка: «Написать», «Взять в работу…»,
                 красное «Снять параллельную…». --}}
            <div id="parallel-{{ $p->id }}" class="bid-line">
                @php ob_start(); @endphp
                    <span class="bid-line-sub"><span class="nums">{{ \App\Support\Ago::html($p->created_at, true, words: false) }}</span></span>
                    <span class="bid-line-sub"><x-ui.state :tone="$tone">{{ $word }}</x-ui.state>@if ($take['locked'])<span>{{ $take['locked'] }}</span>@endif</span>
                @php $sub = ob_get_clean(); @endphp
                <x-ui.more :id="'parallel-menu-'.$p->id" sheet-class="bid-sheet">
                    <x-slot:trigger>
                        <button type="button" class="bid-line-main" data-action="sheet#open" aria-haspopup="dialog" aria-controls="parallel-menu-{{ $p->id }}">
                            <x-ui.avatar :user="$p->buyer" :size="36"/>
                            <span class="min-w-0 flex-1"><span class="bid-line-name">{{ $p->buyer?->shortName() }}</span>{!! $sub !!}</span>
                            <span class="bid-line-end"><span class="bid-amount nums font-semibold">{{ Money::rub($p->amount) }}</span></span>
                            <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-muted"/>
                        </button>
                    </x-slot:trigger>
                    <div class="bid-sheet-head">
                        <div class="flex items-center gap-3">
                            <x-ui.avatar :user="$p->buyer" :size="40"/>
                            <div class="min-w-0 flex-1"><div class="bid-line-name">{{ $p->buyer?->shortName() }}</div>{!! $sub !!}</div>
                            <span class="bid-line-end"><span class="bid-amount nums font-semibold">{{ Money::rub($p->amount) }}</span></span>
                        </div>
                        <div class="bid-sheet-acts">
                            @if ($p->buyer?->hasDeals())<x-offer.chat-button :offer="$offer" :user="$p->buyer" :chats="$chats"/>@endif
                            @unless ($take['locked'])
                                <form method="post" action="/work/deals/{{ $p->id }}/take" class="contents" data-turbo-confirm="{{ $take['ask'] }}" data-turbo-confirm-label="Взять в работу">@csrf<button type="submit" class="btn btn-accent min-w-0 flex-1">Взять в работу…</button></form>
                            @endunless
                        </div>
                    </div>
                    <div class="menu-group">
                        <form method="post" action="/work/deals/{{ $p->id }}/drop" data-turbo-confirm="Снять параллельную у {{ $p->buyer?->shortName() }}? У него сделка отменится" data-turbo-confirm-label="Снять" data-turbo-confirm-danger>@csrf<button type="submit" class="menu-item w-full text-danger"><x-ui.icon name="x" class="size-5"/>Снять параллельную…</button></form>
                    </div>
                </x-ui.more>
                @if ($p->collectsBuyer() || $p->contract?->buyer_user_id)
                    <div class="px-4 pb-3"><div class="list"><x-deal.buyer :deal="$p" :auto-open="false"/></div></div>
                @endif
            </div>
        @endforeach
    </div>
    </div>
@endif
