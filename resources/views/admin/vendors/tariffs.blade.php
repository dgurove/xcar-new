{{-- Договорной прайс вендора поверх базового. --}}
<x-vendor.tariffs :rows="$rows" :yards="$yards" :yard-id="$yardId" :categories="$categories" :services="$services" :vendor="$vendor" :href="$base.'?pill=tariffs'"/>
