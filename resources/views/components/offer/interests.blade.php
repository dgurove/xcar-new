{{-- Интерес к предложению — одна группа строк `.list` в редакторе и в окне строки (13.10.2026, владелец: «продумай, как
     красиво»; до того в редакторе — плашки с тегами). Строка: аватар, имя (свой интерес — «Вы»), фразой — чей покупатель
     или кто внёс, телефон, когда; комментарий строкой ниже; под ним одно действие по состоянию. Последней строкой —
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
                    <span class="block truncate font-medium">{{ $interest->user->is($me) ? 'Вы' : $interest->user->name }}</span>
                    <span class="row-sub">
                        @if ($interest->user->manager)<span>покупатель {{ $interest->user->manager->shortName() }}</span>@endif
                        @if ($interest->placer)<span>{{ $interest->placer->is($me) ? 'вы внесли' : 'внёс '.$interest->placer->shortName() }}</span>@endif
                        @if ($interest->user->phone && ! $interest->user->is($me))<x-ui.phone :user="$interest->user"/>@endif
                        <span class="nums whitespace-nowrap">{{ \App\Support\Ago::html($interest->created_at, true, words: false) }}</span>
                        @if ($interest->state === InterestState::Closed)<x-ui.state tone="closed">закрыт</x-ui.state>@endif
                    </span>
                    @if ($interest->comment)<span class="mt-1 block text-sm">{{ $interest->comment }}</span>@endif
                    {{-- Действие под текстом, как у подтверждений: справа в узкой колонке и на телефоне оно закрывало телефон. --}}
                    @if ($interest->state === InterestState::New && ! $interest->user->is($me))
                        <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="contacted"><button class="btn btn-s btn-quiet">Мы связались</button></form>
                    @elseif ($interest->state !== InterestState::Closed)
                        <form method="post" action="/interests/{{ $interest->id }}" class="mt-2">@csrf<input type="hidden" name="state" value="closed"><button class="btn btn-s btn-quiet">Закрыть</button></form>
                    @endif
                </div>
            </div>
        @endforeach
        @if ($add)<x-offer.interest-for :offer="$offer"/>@endif
    </div>
@endif
