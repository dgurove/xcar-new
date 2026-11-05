{{-- Что видит человек парковки (`Park\City`): весь парк или парковки, письма и счета одного города; «Заявки» — список заявок
     одного города у того, кто видит весь парк (`City::requestsList`). Городов один — полей нет. --}}
@props(['city' => null, 'requests' => null, 'withRequests' => false])
@php $cities = \App\Park\City::options(); $city = old('park_city_id', $city); $requests = old('park_requests_city_id', $requests); @endphp
@if (count($cities) > 1)
    <div class="field">
        <span class="field-label">Видит</span>
        <div class="flex flex-wrap gap-1.5">
            <label class="choice"><input type="radio" name="park_city_id" value="" @checked(! $city)><span>Весь парк</span></label>
            @foreach ($cities as $id => $name)
                <label class="choice"><input type="radio" name="park_city_id" value="{{ $id }}" @checked((int) $city === $id)><span>{{ $name }}</span></label>
            @endforeach
        </div>
    </div>
    @if ($withRequests)
    <div class="field">
        <span class="field-label">Заявки</span>
        <div class="flex flex-wrap gap-1.5">
            <label class="choice"><input type="radio" name="park_requests_city_id" value="" @checked(! $requests)><span>Как видит</span></label>
            @foreach ($cities as $id => $name)
                <label class="choice"><input type="radio" name="park_requests_city_id" value="{{ $id }}" @checked((int) $requests === $id)><span>{{ $name }}</span></label>
            @endforeach
        </div>
    </div>
    @endif
@endif
