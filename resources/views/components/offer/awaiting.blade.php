{{-- Метка «Ждём приём» (13.10.2026): ТС этого предложения ещё в «Заявках на приём» парковки (`Offer::awaitingPark`). Только
     в CRM, сразу после названия, как «На аукцион»; проверяет вызывающий. --}}
<span {{ $attributes->merge(['class' => 'tag tag-await ml-1.5 shrink-0']) }}><x-ui.icon name="clock" class="size-3.5"/>Ждём приём</span>
