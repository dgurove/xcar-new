{{-- Готовый договор строкой документа (16.11.2026, владелец: «договор над „Подписанным договором“»): в «Продаже» CRM — сразу над
     ним (`x-deal.money`), в окне документов — в карточке «Договор» (`x-deal.contract`); под ним «Отправить менеджеру» (`x-deal.send-row`). Только готовый (15.11.2026, владелец:
     «ЗАЧЕМ мы показываем договор, если он НЕ ГОТОВ»): чего не хватает — строками `x-deal.contract-gaps`. --}}
{{-- `attach` — скрепкой справа приложить подписанный (18.11.2026: договор одной строкой — готовый, а подписали — он же подписанный,
     `x-deal.signed-contract docTitle`). --}}
@props(['deal', 'attach' => false])
@php
    $contract = \App\Offers\DealContract::for($deal);
    $doc = new \App\Offers\DkpDocument($contract);
@endphp
@unless ($contract->missing())
    <x-deal.doc-row :href="'/work/deals/'.$deal->id.'/dkp.pdf'" :title="'Договор'.($doc->number() ? ' № '.$doc->number() : ' купли-продажи')" name="dkp.pdf" :file="$doc->fileName()">
        {{-- Отправлен менеджеру — когда (16.11.2026); нет — ниже строка «Отправить». --}}
        @if ($contract->shown_at)<span class="text-ink-muted">у менеджера с {{ \App\Support\Ago::day($contract->shown_at) }}</span>@else<span class="text-ink-muted">не отправлен</span>@endif
        @if ($attach && \App\Offers\Actions\AttachSignedContract::allows($deal))
            <x-slot:acts>
                <form method="post" action="/work/deals/{{ $deal->id }}/signed" enctype="multipart/form-data" class="contents" data-controller="autosubmit">
                    @csrf
                    <label class="btn btn-ghost btn-s px-2 text-ink-muted" title="Приложить подписанный" aria-label="Приложить подписанный">
                        <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit"><x-ui.icon name="clip" class="size-5"/>
                    </label>
                </form>
            </x-slot:acts>
        @endif
    </x-deal.doc-row>
    @unless ($contract->shown_at)<x-deal.send-row :deal="$deal" :action="'/work/deals/'.$deal->id.'/contract/show'"/>@endunless
@endunless
