{{-- Карточки сделки в третьей дорожке редактора — одни у «Сделки» и «Гаража» (07.10.2026, владелец: «гараж — это тоже как
     сделка, приводи к общему виду»): деньги, ДКП, если он у схемы есть, заметка. --}}
<x-deal.money :deal="$deal" id="money"/>
@if ($deal->hasContract())<x-deal.contract :deal="$deal"/>@endif
<x-deal.note :deal="$deal" id="deal-note"/>
