{{-- «Ссылка уже есть» в форме ручной ссылки (`MoneyController::existing`, `ExistingLink`): та ссылка строкой
     (`x-billing.link-row`: кто платит, сумма, ТС, что с ней — нажатие открывает её карточку) и «Открыть ссылку» вместо
     второй. Другую сумму берут в её карточке. «Всё равно создать» — вторая ссылка для доплаты сверх сделки (09.10.2026,
     владелец: «вдруг дополнительные затраты»): та же форма с `force`. --}}
<div class="list-cap">Ссылка уже есть</div>
<div class="list"><x-billing.link-row :link="$link"/></div>
<x-ui.button :href="\App\Http\Admin\MoneyController::linkUrl($link)" block class="mt-3" data-turbo-frame="_top">Открыть ссылку</x-ui.button>
<x-ui.button variant="ghost" block class="mt-1 text-ink-muted" name="force" value="1">Всё равно создать</x-ui.button>
