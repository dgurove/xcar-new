{{-- Новые лоты Мигторга заведены черновиками (`Offers\Intake\MigtorgDrafts`, событие `migtorg` темы `staff`) — компактная
     капсула сверху по центру, как «остров» iPhone (26.10.2026, владелец: «ненавязчиво, ненадолго, всем админам и модераторам
     в CRM»): точка раскрывается в капсулу, число отсчитывается, через несколько секунд капсула сворачивается обратно.
     Нажатие — «Без закупочной цены», свайп вверх — убрать, наведение держит. Узел постоянный, рисует migtorg_island_controller. --}}
<a href="/?preset=nofloor" class="island" id="migtorg-island" data-controller="migtorg-island" data-turbo-permanent hidden
   data-action="pointerenter->migtorg-island#hold pointerleave->migtorg-island#release pointerdown->migtorg-island#press click->migtorg-island#open"
   aria-live="polite">
    <span class="island-icon"><x-ui.spark class="size-4"/></span>
    <span class="island-body">
        <span class="island-title">С Мигторга <span class="island-count nums" data-migtorg-island-target="count">0</span> <span data-migtorg-island-target="noun">новых ТС</span></span>
        <span class="island-sub" data-migtorg-island-target="cars"></span>
    </span>
</a>
