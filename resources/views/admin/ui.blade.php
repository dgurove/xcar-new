<x-ui.shell title="Кит">
    <div class="flex flex-col gap-4">
        <x-ui.card title="Кнопки">
            <div class="flex flex-wrap gap-2">
                <x-ui.button type="button">Основная</x-ui.button>
                <x-ui.button type="button" variant="secondary">Вторичная</x-ui.button>
                <x-ui.button type="button" variant="ghost">Тихая</x-ui.button>
                <x-ui.button type="button" variant="danger">Удалить</x-ui.button>
                <x-ui.button type="button" size="sm">Маленькая</x-ui.button>
                <x-ui.button type="button" disabled>Выключена</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card title="Поля">
            <div class="flex flex-col gap-4">
                <x-ui.field name="demo_text" label="Текст" placeholder="Подсказка"/>
                <x-ui.field name="demo_num" label="Цена" inputmode="numeric" value="1 250 000"/>
                <x-ui.field name="demo_date" label="Дата" type="date"/>
                <x-ui.field name="demo_select" label="Выбор" :options="['a' => 'Первый', 'b' => 'Второй']" placeholder="Не выбрано"/>
                <x-ui.field name="demo_area" label="Описание" type="textarea"/>
                <x-ui.check name="demo_check" :checked="true">Галочка</x-ui.check>
            </div>
        </x-ui.card>

        <x-ui.card title="Чипы и бейджи">
            <div class="flex flex-wrap items-center gap-2">
                <span class="chip">Чип</span>
                <span class="chip bg-accent-soft text-accent-text">Приём идёт</span>
                <span class="chip bg-urgent-soft text-urgent">Последние сутки</span>
                <span class="chip bg-closed-soft text-closed">Закрыт</span>
                <span class="badge">3</span>
                <span class="badge">12</span>
            </div>
        </x-ui.card>

        <x-ui.card title="Шторка и сообщения">
            <div class="flex flex-wrap gap-2" data-controller="sheet">
                <x-ui.button type="button" variant="secondary" data-action="sheet#open">Открыть шторку</x-ui.button>
                <x-ui.button type="button" variant="secondary" onclick="toast('Сохранено')">Сообщение</x-ui.button>
                <x-ui.button type="button" variant="secondary" onclick="toast('Не получилось', 'danger')">Ошибка</x-ui.button>
                <x-ui.sheet id="demo-sheet" title="Шторка">
                    <form class="flex flex-col gap-4" onsubmit="event.preventDefault(); toast('Отправлено')">
                        <x-ui.field name="demo_sheet" label="Поле в шторке" autofocus/>
                        <x-ui.button block>Готово</x-ui.button>
                    </form>
                </x-ui.sheet>
            </div>
        </x-ui.card>

        <x-ui.card title="Вложенный блок">
            <x-ui.card :nested="true">Текст во вложенном блоке. <span class="font-semibold tabular-nums">1 250 000 ₽</span></x-ui.card>
        </x-ui.card>
    </div>
</x-ui.shell>
