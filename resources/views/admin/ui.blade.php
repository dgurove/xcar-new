{{-- Витрина кита (только local): каждый элемент сверяется с localhost:8000. --}}
<x-ui.shell title="Кит">
    <div class="flex flex-col gap-6">
        <x-ui.card title="Кнопки">
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button type="button">Лаймовая</x-ui.button>
                <x-ui.button type="button" variant="secondary">Тихая</x-ui.button>
                <x-ui.button type="button" variant="ghost">Прозрачная</x-ui.button>
                <x-ui.button type="button" variant="danger">Удалить</x-ui.button>
                <x-ui.button type="button" size="s">Маленькая</x-ui.button>
                <x-ui.button type="button" size="s" variant="secondary" round aria-label="Круглая"><x-ui.icon name="filter" class="size-5"/></x-ui.button>
                <x-ui.button type="button" disabled>Выключена</x-ui.button>
            </div>
            <div class="mt-4 rounded-(--radius-l) bg-chrome p-4"><x-ui.button type="button" variant="glass">Стеклянная на хроме</x-ui.button></div>
            <div class="mt-4"><x-ui.button type="button" variant="hero">Смотреть предложения</x-ui.button></div>
        </x-ui.card>

        <x-ui.card title="Поля">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.field name="demo_text" label="Текст" placeholder="Подсказка"/>
                <x-ui.field name="demo_num" label="Цена" inputmode="numeric" value="1 250 000" class="nums"/>
                <x-ui.field name="demo_date" label="Дата" type="date"/>
                <x-ui.field name="demo_select" label="Выбор" :options="['a' => 'Первый', 'b' => 'Второй']" placeholder="Не выбрано"/>
                <x-ui.field name="demo_area" label="Описание" type="textarea" class="sm:col-span-2"/>
                <x-ui.tri name="demo_tri" label="Да / нет / —"/>
                <div class="flex items-end pb-3"><x-ui.check name="demo_check" :checked="true">Галочка</x-ui.check></div>
                <div class="field sm:col-span-2"><span class="field-label">Чипы-переключатели</span><div class="flex flex-wrap gap-2">
                    @foreach (['Капот', 'Крыло', 'Дверь', 'Крыша'] as $i => $z)<label class="choice"><input type="checkbox" switch @checked($i === 1)><span>{{ $z }}</span></label>@endforeach
                </div></div>
                <input class="field-input field-s sm:col-span-2" placeholder="Компактное поле тулбара">
            </div>
        </x-ui.card>

        <x-ui.card title="Пилюли, метки, теги, чипы">
            <x-ui.pills class="mb-4">
                <x-ui.pill href="#" current>Все</x-ui.pill><x-ui.pill href="#">Новые</x-ui.pill><x-ui.pill href="#">Горящие</x-ui.pill><x-ui.pill href="#">Избранное</x-ui.pill>
            </x-ui.pills>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.pill tone="open">Принято</x-ui.pill><x-ui.pill tone="urgent">На рассмотрении</x-ui.pill><x-ui.pill tone="danger">Отклонено</x-ui.pill><x-ui.pill tone="closed">Отозвано</x-ui.pill><x-ui.pill tone="soft">Менеджер свяжется</x-ui.pill><x-ui.pill tone="plain">Согласование</x-ui.pill>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2 rounded-(--radius-l) bg-chrome p-4">
                <span class="mark mark-accent">Новый</span><span class="mark mark-urgent">Заканчивается</span><span class="mark mark-glass">Приём закрыт</span><span class="mark mark-glass nums">2 д 21 ч</span>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <span class="tag">2023</span><span class="tag">Автоматическая</span><span class="tag" style="--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d">С НДС</span><span class="tag" style="--tag-bg:#f0f7d8;--tag-text:#669709;--tag-bg-d:#1a2605;--tag-text-d:#a6cf3a">Срочно</span>
                <span class="chip">Чип</span><span class="chip nums font-normal">12 дн</span><span class="badge">3</span><span class="badge">99+</span>
            </div>
        </x-ui.card>

        <x-ui.card title="Карточки списка">
            @php $offers = \App\Offers\Offer::with(['brand', 'model', 'media'])->where('state', \App\Offers\OfferState::Open)->limit(3)->get(); @endphp
            <p class="mb-3 text-sm text-ink-muted">Плитки</p>
            <div class="cards cards--grid" data-controller="ticker">@foreach ($offers as $o)<x-offer.card :offer="$o"/>@endforeach</div>
            <p class="mb-3 mt-6 text-sm text-ink-muted">Строки</p>
            <div class="cards cards--list">@foreach ($offers as $o)<x-offer.card :offer="$o"/>@endforeach</div>
        </x-ui.card>

        <x-ui.card title="Тулбар">
            <x-ui.toolbar :sorts="['published' => ['Дата публикации', true], 'price' => ['Стоимость', true]]" sort="-published" :pills="['' => 'Все', 'fresh' => 'Новые', 'ending' => 'Горящие']" pill="" name="demo">
                <x-slot:extra><x-ui.view-switch/></x-slot:extra>
                <x-slot:filters><input name="q" placeholder="Марка, модель, VIN" class="field-input field-s"></x-slot:filters>
            </x-ui.toolbar>
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
            <div class="mt-4 flex flex-col gap-2">
                <x-ui.flash>Подтверждение отправлено</x-ui.flash>
                <x-ui.flash tone="danger">Не получилось</x-ui.flash>
                <x-ui.flash tone="accent">Письмо у нас</x-ui.flash>
            </div>
        </x-ui.card>

        <x-ui.card title="Пустое и вложенный блок">
            <x-ui.empty href="#" link="Сбросить фильтры">По этим условиям ничего нет</x-ui.empty>
            <x-ui.card :nested="true" class="mt-4">Вложенный блок. <span class="nums">1 250 000 ₽</span></x-ui.card>
        </x-ui.card>
    </div>
    <x-ui.action-bar><x-ui.button type="button" class="min-w-0 flex-1">Полоса действий</x-ui.button><x-ui.button type="button" variant="secondary" round class="btn-lg" aria-label="Ещё"><x-ui.icon name="more" class="size-6"/></x-ui.button></x-ui.action-bar>
</x-ui.shell>
