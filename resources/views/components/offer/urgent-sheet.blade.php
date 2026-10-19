{{-- «Опубликовать срочно» (12.10.2026, владелец: страховая ждёт ответ к полудню): одним полем «Приём до» (подставлено через
     3 часа) и огненной кнопкой. Открывает событие `urgent:open` (пункт «Действий» карточки, «···» редактора); шторка стоит
     вне меню — поповер прячет вложенное. Только черновику и галерее, админу. --}}
@props(['offer'])
@php $n = $offer->number; @endphp
<div data-controller="sheet" data-action="urgent:open@window->sheet#open" class="contents">
    <x-ui.sheet id="urgent-{{ $n }}" title="Срочно">
        <form method="post" action="/offers/{{ $n }}/state" class="flex flex-col gap-4">
            @csrf
            <input type="hidden" name="state" value="open">
            <input type="hidden" name="when" value="{{ \App\Offers\Slots::URGENT }}">
            <div class="fields">
                <x-ui.field name="urgent_until" :id="'urgent-until-'.$n" label="Приём до" type="datetime-local" required
                    :value="old('urgent_until', \App\Offers\Slots::urgentDefault()->format('Y-m-d\TH:i'))" :min="now()->addMinutes(30)->format('Y-m-d\TH:i')" :limit="\App\Offers\Binding::closeLimit($offer)"/>
            </div>
            <x-ui.sheet-foot class="btn-fire"><x-ui.icon name="fire" fill class="size-5"/> Опубликовать срочно</x-ui.sheet-foot>
        </form>
    </x-ui.sheet>
</div>
