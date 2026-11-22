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
            {{-- Пока страница во фрейме не отрисовалась, Safari рисует пустой фрейм белым в любой теме (22.11.2026, владелец: «тупо белый
                 экран даже в тёмной теме»): фрейм прозрачен до сигнала страницы `deal-ready` (`x-ui.layout`), под ним — силуэт
                 страницы сделки с переливом. --}}
            <div class="deal-frame-wrap">
                <div class="deal-ghost" aria-hidden="true">
                    <span class="skeleton deal-ghost-title"></span>
                    <span class="deal-ghost-chips"><span class="skeleton deal-ghost-chip"></span><span class="skeleton deal-ghost-chip deal-ghost-chip--wide"></span></span>
                    <span class="deal-ghost-plate"><span class="skeleton deal-ghost-avatar"></span><span class="deal-ghost-lines"><span class="skeleton deal-ghost-line"></span><span class="skeleton deal-ghost-line deal-ghost-line--short"></span></span></span>
                    <span class="deal-ghost-block">
                        <span class="skeleton deal-ghost-cap"></span>
                        @foreach ([0, 1, 2] as $i)<span class="deal-ghost-row"><span class="skeleton deal-ghost-line"></span><span class="skeleton deal-ghost-sum"></span></span>@endforeach
                    </span>
                </div>
                <iframe class="deal-frame" name="xcar-deal" data-deal-sheet-target="frame" title="Сделка" allow="clipboard-write; publickey-credentials-get *"></iframe>
            </div>
        </x-ui.sheet>
    </div>
</div>
