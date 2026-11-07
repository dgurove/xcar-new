{{-- Новые лоты Мигторга заведены черновиками (`Offers\Intake\MigtorgDrafts`, событие `migtorg` темы `staff`) — компактная
     капсула сверху по центру, как «остров» iPhone (26.10.2026, владелец: «ненавязчиво, ненадолго, всем админам и модераторам
     в CRM»): точка раскрывается в капсулу, число отсчитывается, через несколько секунд капсула сворачивается обратно.
     Нажатие — «Без закупочной цены», свайп вверх — убрать, наведение держит. Узел постоянный, рисует lots_island_controller. --}}
<a href="/offers?preset=nofloor" class="island" id="lots-island" data-controller="lots-island" data-turbo-permanent hidden
   data-action="pointerenter->lots-island#hold pointerleave->lots-island#release pointerdown->lots-island#press click->lots-island#open"
   aria-live="polite">
    <span class="island-icon"><x-ui.spark class="size-4"/></span>
    <span class="island-body">
        <span class="island-title">С Мигторга <span class="island-count nums" data-lots-island-target="count">0</span> <span data-lots-island-target="noun">новых ТС</span></span>
        <span class="island-sub" data-lots-island-target="cars"></span>
    </span>
</a>
