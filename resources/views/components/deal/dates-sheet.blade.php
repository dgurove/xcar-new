{{-- «Продано» / «Оплачено поставщику» по нажатию на ячейку «Все» (14.11.2026, владелец: «пусть окно по клику открывается»):
     одна шторка на страницу (в строках календарей нет — их было 6 494 кнопки), заполняет `deal_dates_controller` из `data-`
     ячейки: сделка, что правим, дата, машина. «Сохранить» — дата, «Очистить» — снять. `POST /work/deals/{id}/dates` →
     `SetDealDate`: «Продано» на неоплаченной оплачивает наши счета этой датой, «Оплачено» на шаге оплаты поставщику его
     проходит, «Очистить» у закрытой возвращает её на оплату поставщику. --}}
<div data-controller="sheet deal-dates" class="contents">
    <x-ui.sheet id="deal-dates" title="Дата">
        <form method="post" action="" class="flex flex-col gap-4" data-deal-dates-target="form">
            @csrf
            <input type="hidden" name="what" value="" data-deal-dates-target="what">
            <div class="rounded-(--radius-m) bg-surface-2 px-3 py-2.5 font-medium" data-deal-dates-target="car"></div>
            <div class="fields"><x-ui.field type="date" name="at" id="deal-dates-at" label="Дата" :max="now()->toDateString()"/></div>
            <p class="text-sm text-ink-muted" data-deal-dates-target="hint"></p>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-accent min-w-0 flex-1" data-action="sheet#close">Сохранить</button>
                <button type="submit" name="clear" value="1" class="btn btn-quiet" data-action="sheet#close">Очистить</button>
            </div>
        </form>
    </x-ui.sheet>
</div>
