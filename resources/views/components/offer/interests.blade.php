{{-- Интерес к предложению — одна группа строк `.list` в редакторе и в окне строки (13.10.2026, владелец: «продумай, как
     красиво»; до того в редакторе — плашки с тегами). Строка: аватар, имя (свой интерес — «Вы»), фразой — чей покупатель
     или кто внёс, когда; комментарий строкой ниже; под ним одно действие по состоянию. Последней строкой —
     «+ Интерес» за менеджера или на себя (`x-offer.interest-for`), пока предложение принимает интерес. --}}
@props(['offer'])
@php
    use App\Offers\InterestState;
    $me = auth()->user();
    $add = $me?->canManageCrm() && $offer->acceptsInterest();
    $chats = $offer->interests->isNotEmpty() ? \App\Chats\Chat::byManager($offer) : collect();
@endphp
@if ($offer->interests->isNotEmpty() || $add)
    <div class="list">
        @foreach ($offer->interests as $interest)
            @php
                // Чат — с менеджером: интерес покупателя — с его менеджером, свой интерес менеджера — с ним (19.11.2026).
                $talk = $interest->user->hasDeals() ? $interest->user : $interest->user->manager;
                $closable = $interest->state === InterestState::Contacted;
            @endphp
            {{-- Строкой подтверждения (19.11.2026, вариант 3): нажатие — шторка с «Написать» менеджеру и «Закрыть интерес». --}}
            @php $sheet = ($talk && $talk->hasDeals() && $me?->canEditDeals()) || $closable; @endphp
            <div @class(['bid-line', 'bid-line--off' => $interest->state === InterestState::Closed])>
                @php ob_start(); @endphp
                    <span class="bid-line-sub">
                        @if ($interest->user->manager)<span>покупатель {{ $interest->user->manager->shortName() }}</span>@endif
                        @if ($interest->placer)<span>{{ $interest->placer->is($me) ? 'вы внесли' : 'внёс '.$interest->placer->shortName() }}</span>@endif
                        <span class="nums">{{ \App\Support\Ago::html($interest->created_at, true, words: false) }}</span>
                        @if ($interest->state === InterestState::Closed)<x-ui.state tone="closed">закрыт</x-ui.state>@endif
                    </span>
                @php $sub = ob_get_clean(); $name = $interest->user->is($me) ? 'Вы' : $interest->user->name; @endphp
                @php ob_start(); @endphp
                    <x-ui.avatar :user="$interest->user" :size="36"/>
                    {{-- Только имя покупателя и когда (20.11.2026, владелец: «имя менеджера там вообще не нужно»): чей он, «внёс» — в шторке. --}}
                    <span class="min-w-0 flex-1">
                        <span class="bid-line-name">{{ $name }}</span>
                        <span class="bid-line-row">@if ($interest->comment)<span class="min-w-0 truncate text-sm">{{ $interest->comment }}</span>@endif<span class="bid-line-when nums">{{ \App\Support\Ago::html($interest->created_at, true, words: false) }}</span></span>
                        @if ($interest->state === InterestState::Closed)<span class="bid-line-sub"><x-ui.state tone="closed">закрыт</x-ui.state></span>@endif
                    </span>
                    @if ($sheet)<x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-muted"/>@endif
                @php $main = ob_get_clean(); @endphp
                @if ($sheet)
                    <x-ui.more :id="'interest-menu-'.$interest->id" sheet-class="bid-sheet">
                        <x-slot:trigger><button type="button" class="bid-line-main" data-action="sheet#open" aria-haspopup="dialog" aria-controls="interest-menu-{{ $interest->id }}">{!! $main !!}</button></x-slot:trigger>
                        <div class="bid-sheet-head">
                            <div class="flex items-center gap-3"><x-ui.avatar :user="$interest->user" :size="40"/><div class="min-w-0 flex-1"><div class="bid-line-name">{{ $name }}</div>{!! $sub !!}</div></div>
                            @if ($interest->comment)<p class="mt-2 text-sm">{{ $interest->comment }}</p>@endif
                            @if ($talk && $talk->hasDeals() && $me?->canEditDeals())<div class="bid-sheet-acts"><x-offer.chat-button :offer="$offer" :user="$talk" :chats="$chats"/></div>@endif
                        </div>
                        @if ($closable)
                            <div class="menu-group"><form method="post" action="/interests/{{ $interest->id }}">@csrf<input type="hidden" name="state" value="closed"><button type="submit" class="menu-item w-full"><x-ui.icon name="check" class="size-5 text-ink-muted"/>Закрыть интерес</button></form></div>
                        @endif
                    </x-ui.more>
                @else
                    <div class="bid-line-main">{!! $main !!}</div>
                @endif
            </div>
        @endforeach
        @if ($add)<x-offer.interest-for :offer="$offer"/>@endif
    </div>
@endif
