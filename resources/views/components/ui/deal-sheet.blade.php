{{-- Сделка поверх чата и почты — только в XCAR Chat и XCAR Mail (20.11.2026, владелец: «открытие сделки поверх чата, окно, которое можно
     открывать и закрывать, пока переписываешься; у сотрудников всё то же, что в CRM, у менеджеров — что у них в xcar»).
     Один лист на экран чата: ссылки к машине и сделке помечены `data-deal-sheet` (`Chat::carHref`, `sheetAttrs`), их ловит
     `deal_sheet_controller` и грузит страницу во фрейм; закрыли — фрейм пустеет. Заголовка у листа нет: название и номер
     (копируется нажатием) — в самой странице. --}}
{{-- `deal-sheet` во flash — открыть сразу эту страницу: «Завести» из письма в XCAR Mail возвращает к письму с черновиком поверх;
     `?deal=` / `?car=` в адресе (ссылки уведомлений XCAR Pay) — сделку или машину (`DealSheet::requested`). --}}
@php $open = session('deal-sheet') ?? \App\Support\DealSheet::requested(); @endphp
<div class="contents" data-controller="deal-sheet" data-action="click@window->deal-sheet#click" @if ($open) data-deal-sheet-open-value="{{ $open }}" @endif>
    <div class="contents" data-controller="sheet" data-action="deal-sheet:open@window->sheet#open">
        <x-ui.sheet id="deal-sheet" tall class="sheet-deal" data-deal-sheet-target="dialog">
            {{-- Имя окна `xcar-deal` — метка «я во фрейме шторки» для страницы (`x-ui.layout`): держится при переходах внутри фрейма.
                 Face ID и ключи доступа — на случай замка устройства во фрейме. --}}
            <iframe class="deal-frame" name="xcar-deal" data-deal-sheet-target="frame" title="Сделка" allow="clipboard-write; publickey-credentials-get *"></iframe>
        </x-ui.sheet>
    </div>
</div>
