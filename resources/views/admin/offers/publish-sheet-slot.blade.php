{{-- Шторка «Выставить в продажу» к кнопке в блоке «Цены» (`price-steps`, карточка «Цены» редактора) — вне формы карточки и редактора, открывается событием. --}}
<div class="contents" data-controller="sheet" data-action="publish-{{ $n }}:open@window->sheet#open">
    <x-offer.publish-sheet :offer="$offer" :id="'publish-'.$n" :action="'/offers/'.$n.'/state'" state="open" title="Выставить в продажу"/>
</div>
