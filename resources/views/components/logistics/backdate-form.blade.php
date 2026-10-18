{{-- «Уже вывезли» (18.10.2026): вывоз задним числом — когда вывезли (по умолчанию сейчас), куда привезли (строками с
     галкой, как в шторке «Забрал»), ключи по желанию (не
     отмечено — не знаем, поле машины не трогаем), комментарий. Одна форма на карточку задачи (`transport`) и пачку
     галочек на «Без исполнителя» (`transport` нет: «К менеджеру» — его менеджеру сделки или гаража, у кого его нет, та
     задача не пройдёт). action — `/transports/{id}/backdate` или `/transports/backdate` (PUT, выбранные — `pick#carry`). --}}
@props(['transport' => null, 'action', 'prefix' => 'backdate'])
@php
    use App\Logistics\Choices;
    use App\Logistics\Point;
    use App\Logistics\Transport;
    $pickup = ! $transport || $transport->isPickup();
    $keeper = $transport ? Transport::keeperLabel($transport->saleOffer()) : 'К менеджеру сделки или гаража';
    // Места по названию, как в «Куда везти» (`Point::choices`): менеджер, каждая наша парковка, адрес.
    $points = Point::choices($keeper);
    $point = (string) old('destination', match (true) {
        $transport?->destination === Point::Yard && $transport->to_yard_id => 'yard:'.$transport->to_yard_id,
        $transport?->destination !== null && $transport->destination !== Point::Ours => $transport->destination->value,
        default => array_key_first($points),
    });
@endphp
<form method="post" action="{{ $action }}" {{ $attributes->merge(['class' => 'flex flex-col gap-4']) }} data-controller="reveal">
    @csrf
    {{ $slot }}
    <div class="fields">
        <x-ui.field name="picked_at" :id="$prefix.'-at'" label="Когда" type="datetime-local" :value="old('picked_at', now()->format('Y-m-d\TH:i'))" :max="now()->format('Y-m-d\TH:i')" required/>
    </div>
    @if ($pickup)
        <div>
            <h3 class="list-head">Куда привезли</h3>
            <div class="list">
                @foreach ($points as $value => $label)
                    <label class="row row-check">
                        <span class="min-w-0 flex-1">{{ $label }}</span>
                        <span class="check"><input type="radio" name="destination" value="{{ $value }}" @checked((string) $point === (string) $value) data-action="reveal#pick"></span>
                    </label>
                @endforeach
            </div>
        </div>
        <div class="fields" data-reveal-target="pane" data-reveal-key="other" @if ($point !== 'other') hidden @endif>
            <x-ui.field name="to_address" :id="$prefix.'-address'" label="Адрес" type="textarea" :value="old('to_address')"/>
        </div>
        <div>
            <h3 class="list-head">Сколько ключей</h3>
            <x-ui.segment name="keys" :options="[0 => 'Нет', 1 => '1', 2 => '2', 3 => '3+']" :value="old('keys')"/>
        </div>
        <div class="fields"><x-ui.field name="pickup_note" :id="$prefix.'-note'" label="Комментарий" type="textarea" :value="old('pickup_note')"/></div>
    @endif
    <x-ui.sheet-foot>{{ $transport ? 'Отметить вывезенной' : 'Отметить вывезенными' }}</x-ui.sheet-foot>
</form>
