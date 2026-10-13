{{-- «Предложено из заявок на приём» (13.10.2026, владелец): черновик, что ТС Альфы выставила сама (`Park\Actions\AutoSale`),
     ждёт решения админа — наверху окошка и редактора. «Принять» — плашка уходит, черновик остаётся в своей вкладке;
     «Отклонить» — черновик удаляется, ТС остаётся на парковке со всеми файлами и сама больше не выставляется. Анимация —
     `proposal_controller`: волна и галочка у «Принять», плашка гаснет у «Отклонить». Проверяет `proposed()` вызывающий. --}}
@props(['offer'])
<div {{ $attributes->class('proposal') }} data-controller="proposal">
    <div class="proposal-body">
        <span class="proposal-mark" aria-hidden="true"><x-ui.spark class="size-5 proposal-spark"/><x-ui.icon name="check" class="size-5 proposal-check"/></span>
        <p class="min-w-0 flex-1 text-ink">Это ТС было автоматически предложено из заявок на приём Park XCar</p>
    </div>
    <div class="proposal-acts">
        <form method="post" action="/offers/{{ $offer->number }}/proposal" data-action="submit->proposal#decline">
            @csrf @method('delete')
            <button class="btn btn-s btn-quiet text-danger">Отклонить</button>
        </form>
        <form method="post" action="/offers/{{ $offer->number }}/proposal" data-action="submit->proposal#accept">
            @csrf
            <button class="btn btn-s btn-accent">Принять</button>
        </form>
    </div>
</div>
