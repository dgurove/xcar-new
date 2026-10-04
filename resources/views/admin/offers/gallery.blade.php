{{-- Кадр парковки (приём, письма её ящиков) — запись парковки о машине: из продажи его убирает глаз, корзины нет.
     Заглушки в конце — кадры, которые ещё качаются с Мигторга и прикрепляются из письма. --}}
<x-ui.photos :photos="$offer->photos()" :deletable="fn ($m) => ! \App\Park\Sale::parkOwned($m)" :pending="\App\Offers\Jobs\ImportMigtorgLot::pending($offer->id) + \App\Mail\Jobs\ImportThreadFiles::pending($offer)"/>
