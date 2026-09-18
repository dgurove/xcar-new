{{-- Базовый прайс ПРАЙМ: по категориям ТС, площадкам и услугам; договорные цены вендоров — на их карточках. --}}
<x-ui.cabinet title="Тарифы">
    <x-vendor.tariffs :rows="$rows" :yards="$yards" :yard-id="$yardId" :categories="$categories" :services="$services" :href="$base"/>
</x-ui.cabinet>
