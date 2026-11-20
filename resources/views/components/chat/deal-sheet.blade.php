{{-- Сделка поверх чата — только в XCAR Chat (20.11.2026, владелец: «открытие сделки поверх чата, окно, которое можно
     открывать и закрывать, пока переписываешься; у сотрудников всё то же, что в CRM, у менеджеров — что у них в xcar»).
     Один лист на экран чата: ссылки к машине и сделке помечены `data-deal-sheet` (`Chat::carHref`, `sheetAttrs`), их ловит
     `deal_sheet_controller` и грузит страницу во фрейм; закрыли — фрейм пустеет. Заголовка у листа нет: название и номер
     (копируется нажатием) — в самой странице. --}}
<div class="contents" data-controller="deal-sheet" data-action="click@window->deal-sheet#click">
    <div class="contents" data-controller="sheet" data-action="deal-sheet:open@window->sheet#open">
        <x-ui.sheet id="deal-sheet" tall class="sheet-deal" data-deal-sheet-target="dialog">
            {{-- Имя окна `xcar-deal` — метка «я во фрейме шторки» для страницы (`x-ui.layout`): держится при переходах внутри фрейма.
                 Face ID и ключи доступа — на случай замка устройства во фрейме. --}}
            <iframe class="deal-frame" name="xcar-deal" data-deal-sheet-target="frame" title="Сделка" allow="clipboard-write; publickey-credentials-get *"></iframe>
        </x-ui.sheet>
    </div>
</div>
