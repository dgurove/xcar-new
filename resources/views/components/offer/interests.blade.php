{{-- Интерес к предложению — одна группа строк `.list` в редакторе и в окне строки (13.10.2026, владелец: «продумай, как
     красиво»; до того в редакторе — плашки с тегами). Строка: аватар, имя (свой интерес — «Вы»), фразой — чей покупатель
     или кто внёс, когда; комментарий строкой ниже; под ним одно действие по состоянию. Последней строкой —
     «+ Интерес» за менеджера или на себя (`x-offer.interest-for`), пока предложение принимает интерес. --}}
@props(['offer'])
@php
    use App\Offers\InterestState;
    $me = auth()->user();
    $add = $me?->canManageCrm() && $offer->acceptsInterest();
@endphp
@if ($offer->interests->isNotEmpty() || $add)
    <div class="list">
        @foreach ($offer->interests as $interest)
            <div class="row">
                <x-ui.avatar :user="$interest->user" :size="36"/>
                <div class="min-w-0 flex-1">
                    {{-- Когда — справа в строке имени (16.11.2026, владелец: «тогда будет две строки, а не три»). --}}
                    <span class="flex items-baseline gap-2"><span class="min-w-0 flex-1 truncate font-medium">{{ $interest->user->is($me) ? 'Вы' : $interest->user->name }}</span><span class="nums shrink-0 whitespace-nowrap text-sm text-ink-dim">{{ \App\Support\Ago::html($interest->created_at, true, words: false) }}</span></span>
                    @if ($interest->user->manager || $interest->placer || $interest->state === InterestState::Closed)
                    <span class="row-sub">
                        @if ($interest->user->manager)<span>покупатель {{ $interest->user->manager->shortName() }}</span>@endif
                        @if ($interest->placer)<span>{{ $interest->placer->is($me) ? 'вы внесли' : 'внёс '.$interest->placer->shortName() }}</span>@endif
                        @if ($interest->state === InterestState::Closed)<x-ui.state tone="closed">закрыт</x-ui.state>@endif
                    </span>
                    @endif
                    @if ($interest->comment)<span class="mt-1 block text-sm">{{ $interest->comment }}</span>@endif
                    {{-- Без телефона и без «Мы связались» (16.11.2026, владелец: «кнопка не нужна», «номера телефонов тут не нужны»);
                         «Закрыть» — только у отмеченных раньше «связались». --}}
                    @if ($interest->state === InterestState::Contacted)
                        <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="closed"><button class="btn btn-s btn-quiet">Закрыть</button></form>
                    @endif
                </div>
            </div>
        @endforeach
        @if ($add)<x-offer.interest-for :offer="$offer"/>@endif
    </div>
@endif
