{{-- «Отдать на оценку» пачкой — плашка галочек «Без продажной цены» (22.11.2026, начальник: «как в Оцененных — выделять
     галочками и отдать менеджеру или менеджерам сразу пачкой»). Поля — те же, что у одной машины (`x-offer.estimate-fields`);
     «Заявленная: Показать | Скрыть» — один ответ на все отмеченные, у машины без заявленной она скрыта сама. Номера — галочки
     списка (`formdata->pick#carry`); отдали — галочки снимаются (`pick#done`), строки остаются на месте. --}}
@props(['managers'])
<div class="contents" data-controller="sheet">
    <x-ui.button type="button" class="min-w-0 flex-1" data-action="sheet#open">Отдать на оценку <span class="nums" data-pick-target="count"></span></x-ui.button>
    <x-ui.sheet id="estimate-pack" title="Отдать на оценку">
        <form method="post" action="/offers/estimates" class="flex flex-col gap-4" data-controller="select"
            data-action="formdata->pick#carry turbo:submit-end->pick#done">
            @csrf
            <x-offer.estimate-fields :managers="$managers" :declared="true" exclusive/>
        </form>
    </x-ui.sheet>
</div>
