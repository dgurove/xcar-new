{{-- «Отдать менеджеру на оценку» (11.10.2026): менеджеры строками с галкой, поиск от девяти, кому уже отдано — отмечено и
     не нажимается; отметил — под ним «Заявленная N ₽: Показать | Скрыть» без умолчания (владелец: «пусть просто
     спрашивает»), «Отдать (N)» живая, когда ответили за всех; внизу. Под списком сроки сегментами (02.11.2026, Андрей):
     «На оценку» дней и, когда отмечен один, «Потом только ему» — эксклюзив после оценки (`FirstLook`); у грузового
     (`Offer::isHeavy`) первым и отмеченным стоит свой менеджер (`xcar.heavy_estimator`), эксклюзив — 5 дней. Кнопка шторки — строкой в группе оценок; trigger=false — только шторка, её открывает
     событие `estimate-ask-{n}:open` (пункт «Спросить менеджеров» в «Действиях» карточки «Оцененных»). --}}
@props(['offer', 'managers', 'label' => 'Отдать на оценку', 'trigger' => true])
@php
    $n = $offer->number;
    $asked = $offer->openEstimates->pluck('user_id')->all();
    $declared = $offer->declaredPrice();
    // Грузовое — свой менеджер первой строкой и уже отмечен; эксклюзив по умолчанию 5 дней (корректировке цены его нет).
    $pick = ! $offer->asking_price && $offer->isHeavy() ? (int) config('xcar.heavy_estimator') : null;
    $pick = $pick && $managers->contains('id', $pick) && ! in_array($pick, $asked, true) ? $pick : null;
    $managers = $pick ? $managers->sortBy(fn ($m) => $m->id === $pick ? 0 : 1)->values() : $managers;
@endphp
<div data-controller="sheet" class="contents" data-action="estimate-ask-{{ $n }}:open@window->sheet#open">
    @if ($trigger)<button type="button" class="row w-full text-left text-accent-text" data-action="sheet#open"><x-ui.row-icon name="plus" tone="accent" size="s"/><span class="min-w-0 flex-1">{{ $label }}</span></button>@endif
    <x-ui.sheet id="estimate-ask-{{ $n }}" :title="$offer->asking_price ? 'Спросить менеджеров' : 'Отдать на оценку'">
        {{-- Ни закупочной, ни заявленной (14.10.2026, владелец): отдать можно, но шторка переспрашивает; менеджер видит
             черновик как при «Скрыть» — без заявленной и без порога. --}}
        <form method="post" action="/offers/{{ $n }}/estimates" class="flex flex-col gap-4" data-controller="select"
            @if (! $declared) data-turbo-confirm="Вы уверены, что хотите отправить на оценку без закупочной цены?" data-turbo-confirm-label="Отдать" @endif>
            @csrf
            <x-offer.estimate-fields :managers="$managers" :asked="$asked" :pick="$pick" :declared="$declared ?: null"
                :exclusive="! ($offer->asking_price || $asked)" :submit="$offer->asking_price ? 'Спросить' : 'Отдать'"/>
        </form>
    </x-ui.sheet>
</div>
