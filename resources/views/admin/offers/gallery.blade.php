{{-- Кадр парковки (приём, письма её ящиков) — запись парковки о машине: из продажи его убирает глаз, корзины нет. --}}
<x-ui.photos :photos="$offer->photos()" :deletable="fn ($m) => ! \App\Park\Sale::parkOwned($m)"/>
