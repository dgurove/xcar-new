{{-- Письма и доп. просьбы поставщику одним куском — страница машины (в карточке) и окно машины (разделом), 23.11.2026, владелец:
     «единые модули». Есть письма — `x-mail.letters` с «Написать / Ответить» и «+ Доп. просьба» словами в строке темы, прошлые
     просьбы — над письмами; писем нет — блок просьб с «Сделать доп. просьбу». Ждёт `$mailCard` (письма и почта CRM),
     `$extraRequest`, `$messages`, `$asks`, `$stages`, `$offer`, `$n`. --}}
@if ($mailCard)
    {{-- «Ответить» и «Доп. просьба» — лаймовыми словами справа под темой писем (23.11.2026, владелец выбрал из макетов). --}}
    @php $hasAsks = $extraRequest && \App\Offers\ExtraAsk::where('offer_id', $offer->id)->exists(); @endphp
    <x-mail.letters :messages="$messages" :url="'/offers/'.$n.'/letters'" :asks="$asks" :stages="$stages" split>
        <x-slot:tools>
            @if ($extraRequest)<button type="button" class="letters-act" data-controller="emit" data-action="emit#send" data-emit-event-param="letters:open" data-emit-url-param="/work/mail/new?offer={{ $n }}&extra=1"><x-ui.icon name="plus" class="size-4"/>Доп. просьба</button>@endif
        </x-slot:tools>
        @if ($hasAsks)
            <x-slot:above><x-offer.extra-request :offer="$offer" :messages="$messages" :compose="false" class="mb-4"/></x-slot:above>
        @endif
    </x-mail.letters>
@elseif ($extraRequest)
    <x-offer.extra-request :offer="$offer" :messages="$messages"/>
@endif
