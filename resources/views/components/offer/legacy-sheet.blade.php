{{-- «Добавить номера» во вкладке «Из таблицы Кати»: номера убытка столбцом из таблицы, через пробел или запятую.
     Сервер раскладывает: черновики — во вкладку, незаведённые — призрачными строками, в сделке — списком над таблицей. --}}
<div class="contents" data-controller="sheet">
    <button type="button" class="btn btn-s btn-quiet toolbar-wide shrink-0 rounded-full" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/>Добавить номера</button>
    <x-ui.sheet id="legacy-add" title="Номера убытка" :open="$errors->has('claims')">
        <form method="post" action="/offers/legacy" class="flex flex-col gap-4">
            @csrf
            <div class="fields">
                <x-ui.field name="claims" label="Номера" type="textarea" rows="8" placeholder="812865/2026" autocomplete="off" spellcheck="false" required autofocus/>
            </div>
            <div class="sheet-foot"><x-ui.button block>Добавить</x-ui.button></div>
        </form>
    </x-ui.sheet>
</div>
