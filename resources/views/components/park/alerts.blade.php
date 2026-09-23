{{-- Предупреждения по ТС тегами у наименования: ставки нет, парковки нет, VIN, письма, бумаги вендору. --}}
@props(['vehicle'])
@foreach (\App\Park\Alerts::of($vehicle) as $alert)
    <span class="tag {{ $alert['tone'] === 'danger' ? 'tag-danger' : 'tag-urgent' }}">{{ $alert['label'] }}</span>
@endforeach
