{{-- Подтверждение строкой («Мои подтверждения»): фото, название, под ним — состояние слева и цена справа. Номера нет (16.11.2026,
     владелец: «номер не нужен»); ждёт решения — «На согласовании» (там же: «не „итоги после дата время“»), и в резерве так же — о
     чужой сделке ни слова (15.10.2026). Гаражное — словом «В гараж» вместо цены. --}}
@php
    $offer = $bid->offer;
    $price = $bid->isGarage() ? 'В гараж' : \App\Support\Money::rub($bid->amount);
@endphp
<a href="/offers/{{ $offer->number }}" class="row">
    <span class="row-photo max-sm:!h-[2.625rem] max-sm:!w-14"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
    <span class="min-w-0 flex-1">
        <span class="block truncate font-medium">{{ $offer->titleWithYear() }}</span>
        <span class="mt-0.5 flex items-baseline gap-x-3">
            <span class="min-w-0 flex-1 text-sm text-ink-muted">На согласовании</span>
            <span class="nums shrink-0 font-medium">{{ $price }}</span>
        </span>
    </span>
</a>
