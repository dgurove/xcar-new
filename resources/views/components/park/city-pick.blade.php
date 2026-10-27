{{-- Город не определён (`CityGuess`: отправитель писал и для Москвы, и для Питера, или вендор работает в обоих) — ТС видят
     управляющие всех городов, пока её не примут. Здесь её решают одним нажатием (`SetCity`): управляющему одного города —
     «Это наш город», остальным — кнопка на каждый город парковок. Решено — строки нет. --}}
@props(['vehicle'])
@php
    use App\Park\City;
    $own = City::of(auth()->user());
    $cities = $own ? [$own => City::name($own)] : City::options();
@endphp
@if (! $vehicle->city_id && ! $vehicle->yard_id && count(City::options()) > 1)
    <form method="post" action="/cars/{{ $vehicle->id }}/city" {{ $attributes->merge(['class' => 'city-pick']) }}>@csrf
        <span class="city-pick-text">Город не определён — видят все города</span>
        <span class="city-pick-acts">
            @foreach ($cities as $id => $name)
                <button type="submit" name="city" value="{{ $id }}" class="btn btn-s {{ $own ? 'btn-accent' : 'btn-quiet' }}">{{ $own ? 'Это наш город' : $name }}</button>
            @endforeach
        </span>
    </form>
@endif
