{{-- «Параллельно» — сделки, поручённые параллельно (07.11.2026, `Offers\Parallels`): у каждой — менеджер, его цена, где он у
     себя (его ход, «реквизиты есть»), его покупатель с данными (`x-deal.buyer`: посмотреть, скан PDF, заполнить за него) и
     «Взять в работу» — переключить сделку на него (`TakeParallel`; что будет — в подтверждении), «Снять» — закрыть одну.
     Стоит в карточке «Сделка» между принятым и резервом, а без идущей (главную отменили) — над подтверждениями. --}}
@php
    use App\Support\Money;
    use App\Workflow\Asks;
    $main ??= null;
    $parallels = \App\Offers\Parallels::of($offer)->each(fn ($p) => $p->setRelation('offer', $offer));
    $main?->setRelation('offer', $offer);
    // Почему нельзя переключить — общее для всех параллельных: один раз на блок.
    $locked = $main && $parallels->isNotEmpty() ? \App\Offers\Parallels::locked($main) : null;
@endphp
@if ($parallels->isNotEmpty())
    @unless ($bare ?? false)<div id="parallel" class="scroll-mt-24 mt-2 px-1 text-sm text-ink-dim">Параллельно <span class="nums">{{ $parallels->count() }}</span></div>@endunless
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
            <div class="px-4 py-3" id="parallel-{{ $p->id }}">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="nums whitespace-nowrap text-lg font-semibold">{{ Money::rub($p->amount) }}</span>
                    <x-ui.state :tone="$tone">{{ $word }}</x-ui.state>
                    <span class="nums ml-auto whitespace-nowrap text-sm text-ink-dim">{{ \App\Support\Ago::html($p->created_at, true, words: false) }}</span>
                </div>
                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm"><x-ui.person :user="$p->buyer" full/>@if ($p->buyer?->phone)<x-ui.phone :user="$p->buyer"/>@endif</div>
                @if ($p->collectsBuyer() || $p->contract?->buyer_user_id)
                    <div class="list mt-2"><x-deal.buyer :deal="$p" :auto-open="false"/></div>
                @endif
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    @if ($take['locked'])
                        <span class="text-sm text-ink-dim">{{ $take['locked'] }}</span>
                    @else
                        <form method="post" action="/work/deals/{{ $p->id }}/take" data-turbo-confirm="{{ $take['ask'] }}" data-turbo-confirm-label="Взять в работу">@csrf<x-ui.button size="sm">Взять в работу</x-ui.button></form>
                    @endif
                    <form method="post" action="/work/deals/{{ $p->id }}/drop" data-turbo-confirm="Снять параллельную у {{ $p->buyer?->shortName() }}? У него сделка отменится" data-turbo-confirm-label="Снять">@csrf<x-ui.button size="sm" variant="ghost">Снять</x-ui.button></form>
                </div>
            </div>
        @endforeach
    </div>
@endif
