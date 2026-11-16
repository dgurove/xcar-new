{{-- Готовый договор строкой документа (16.11.2026, владелец: «договор над „Подписанным договором“»): в «Продаже» CRM — сразу над
     ним (`x-deal.money`), в окне документов — в карточке «Договор» (`x-deal.contract`). Только готовый (15.11.2026, владелец:
     «ЗАЧЕМ мы показываем договор, если он НЕ ГОТОВ»): чего не хватает — строками `x-deal.contract-gaps`. --}}
@props(['deal'])
@php
    $contract = \App\Offers\DealContract::for($deal);
    $doc = new \App\Offers\DkpDocument($contract);
@endphp
@unless ($contract->missing())
    <x-deal.doc-row :href="'/work/deals/'.$deal->id.'/dkp.pdf'" :title="'Договор'.($doc->number() ? ' № '.$doc->number() : ' купли-продажи')" name="dkp.pdf" :file="$doc->fileName()">
        <span class="text-open">готов</span>
    </x-deal.doc-row>
@endunless
