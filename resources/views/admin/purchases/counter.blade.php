{{-- Предпросмотр ответа поставщика: строки файла, разложенные по тому, что с ними будет. Станут черновиками —
     ТС закупки с итоговой ценой (она справа, это закупочная); ниже то, что не перенесётся. Поставщика нет —
     выбор тут же, без него предложениям не назначить вендора. --}}
@php
    use App\Support\{Money, Plural};
    $n = $purchase->number;
    $move = $groups['move'];
    $heads = ['moved' => 'Уже в предложениях', 'unpriced' => 'Без итоговой цены', 'missing' => 'Нет в закупке'];
@endphp
<x-ui.shell title="Контрпредложение" :back="['Закупка', '/purchases/'.$n]" narrow>
    @if ($errors->any())<x-ui.flash tone="danger" class="mb-4">{{ $errors->first() }}</x-ui.flash>@endif
    @unless ($purchase->vendor)
        <form method="post" action="/purchases/{{ $n }}" class="mb-4" data-controller="autosubmit">
            @csrf @method('put')
            <x-ui.field name="vendor_id" label="Поставщик" :options="$vendors->all()" placeholder="Выберите" data-action="change->autosubmit#submit"/>
        </form>
    @endunless

    @if ($move)
        <div class="list-head">Станут черновиками <span class="nums">{{ count($move) }}</span></div>
        <div class="list">
            @foreach ($move as $row)
                <div class="row">
                    <span class="row-photo"><x-offer.photo :media="$row['car']->mainPhoto()" sizes="72px"/></span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate">{{ $row['car']->titleWithYear() }}</div>
                        <div class="row-sub text-sm text-ink-dim"><span class="nums">{{ $row['dl'] }}</span></div>
                    </div>
                    <span class="nums shrink-0 whitespace-nowrap font-medium">{{ Money::rub($row['price']) }}</span>
                </div>
            @endforeach
        </div>
    @else
        <x-ui.empty>Переносить нечего</x-ui.empty>
    @endif

    @foreach ($heads as $key => $head)
        @if ($groups[$key])
            <div class="list-head mt-4">{{ $head }} <span class="nums">{{ count($groups[$key]) }}</span></div>
            <div class="list">
                @foreach ($groups[$key] as $row)
                    <div class="row">
                        <div class="min-w-0 flex-1">
                            <div class="truncate">{{ $row['car']?->titleWithYear() ?? trim($row['brand'].' '.$row['model']) ?: 'ТС' }}</div>
                            <div class="row-sub text-sm text-ink-dim"><span class="nums">{{ $row['dl'] }}</span></div>
                        </div>
                        @if ($key === 'moved')<a href="/offers/{{ $row['car']->offer->number }}" class="chip nums shrink-0">№ {{ $row['car']->offer->number }}</a>@endif
                    </div>
                @endforeach
            </div>
        @endif
    @endforeach

    @if ($move)
        <form method="post" action="/purchases/{{ $n }}/counter/move" class="action-bar">
            <div class="action-bar-inner">@csrf<input type="hidden" name="path" value="{{ $path }}">
            <x-ui.button class="min-w-0 flex-1" :disabled="! $purchase->vendor">Создать {{ count($move) }} {{ Plural::of(count($move), ['черновик', 'черновика', 'черновиков']) }}</x-ui.button></div>
        </form>
    @endif
</x-ui.shell>
