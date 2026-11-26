{{-- Готовый договор строкой документа (16.11.2026, владелец: «договор над „Подписанным договором“»): в «Продаже» CRM — сразу над
     ним (`x-deal.money`), в окне документов — в карточке «Договор» (`x-deal.contract`). Только готовый (15.11.2026, владелец:
     «ЗАЧЕМ мы показываем договор, если он НЕ ГОТОВ»): чего не хватает — строками `x-deal.contract-gaps`.
     Вариант Б (19.11.2026, владелец выбрал из макетов):
     - договор — значком Word (он и есть исходник для правки), скачивание спрашивает формат: Word или PDF (`x-deal.dkp-download`);
     - не отправлен — под строкой «Заполнить…» главной и «Отправить как есть» второй (19.11.2026, владелец: «желательно, чтобы
       заполняли, а не в сыром виде отправляли»): шторка «Заполнить договор» — 1) скачать Word (с номером договора,
       `dkp.docx?edit=1`), 2) приложить заполненный (`EditContract`);
     - приложен заполненный — метка «заполнен», справа ↶ «Вернуть сформированный», под строкой одна «Отправить менеджеру»;
       данные сделки поменялись после правки — красным (`ContractEdit::stale`);
     - отправлен — под строкой «Приложить подписанный» | «Отозвать» (`ShowToManager::recallContract`, заполненный файл убирает); отправлен ли — словом подписи
       «Договор» (`x-deal.doc-cap` в `x-deal.money`).
     `attach` — «Приложить подписанный» кнопкой (19.11.2026; прежде скрепкой справа). --}}
@props(['deal', 'attach' => false])
@php
    use App\Offers\ContractEdit;
    $contract = \App\Offers\DealContract::for($deal);
    $doc = new \App\Offers\DkpDocument($contract);
    $base = '/work/deals/'.$deal->id;
    $edited = ContractEdit::has($deal);
    $original = $edited ? ContractEdit::original($deal) : null;
    $isPdf = $original && ContractEdit::isPdf($original);
    $stale = $edited && ContractEdit::stale($deal);
    $sent = $contract->shown_at !== null;
    $staff = (bool) auth()->user()?->canEditDeals();
    $canAttach = $attach && \App\Offers\Actions\AttachSignedContract::allows($deal);
    $canEdit = $staff && $deal->buyer_id && \App\Offers\Actions\EditContract::allows($deal);
    $canRecall = $staff && $deal->buyer_id && $sent && \App\Offers\Actions\ShowToManager::canRecallContract($deal);
    $title = 'Договор'.($doc->number() ? ' № '.$doc->number() : ' купли-продажи');
    $name = preg_replace('/\.pdf$/', '', $doc->fileName());
    $sheet = 'contract-edit-'.$deal->id;
@endphp
@if ($edited || ! $contract->missing())
    <div class="contents">
        <x-deal.doc-row :href="$base.'/dkp.pdf'" :title="$title" :name="$isPdf ? 'dkp.pdf' : 'dkp.docx'" :bytes="$original?->size" :file="$name.'.pdf'" id="dkp-row-{{ $deal->id }}">
            {{-- Отправлен ли — словом подписи «Договор» над блоком (`x-deal.doc-cap`, 19.11.2026); здесь — только свойство файла. --}}
            @if ($edited)<span class="tag">заполнен</span>@endif
            @if ($stale)<span class="text-danger">данные сделки менялись после заполнения</span>@endif
            <x-slot:dl><x-deal.dkp-download :deal="$deal" :base="$base" :name="$name" :word="! $isPdf"/></x-slot:dl>
            @if ($edited && ! $sent && $staff)
                <x-slot:acts>
                    @if ($edited && ! $sent && $staff)
                        <form method="post" action="{{ $base }}/contract/edited" class="contents" data-turbo-confirm="Вернуть сформированный договор? Заполненный файл удалится">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-s px-1.5 text-ink-muted" title="Вернуть сформированный" aria-label="Вернуть сформированный"><x-ui.icon name="undo" class="size-5"/></button>
                        </form>
                    @endif
                </x-slot:acts>
            @endif
        </x-deal.doc-row>
        {{-- Не отправлен — выбор под строкой: главная «Заполнить…» (через Word), второй — как есть. Заполненный — одна лаймовая
             «Отправить менеджеру». --}}
        @if (! $sent && $staff && $deal->buyer_id)
            <div class="send-under send-pair">
                @if ($canEdit && ! $edited)
                    <button type="button" class="btn btn-accent btn-s gap-2" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open"><x-ui.icon name="edit" class="size-4"/>Заполнить…</button>
                @endif
                {{-- Реквизиты покупателя не проверены — «Проверить и отправить» (26.11.2026, `x-deal.buyer-check`). --}}
                @if ($deal->contract?->needsBuyerCheck())
                    <x-deal.buyer-check :deal="$deal" then="contract" label="Отправить менеджеру" @class(['btn-quiet' => $canEdit && ! $edited])/>
                @else
                <form method="post" action="{{ $base }}/contract/show" class="contents">
                    @csrf
                    <button type="submit" @class(['btn btn-s gap-2', 'btn-quiet' => $canEdit && ! $edited, 'btn-accent' => ! ($canEdit && ! $edited)])><x-ui.icon name="send" class="size-4"/>{{ $edited || ! $canEdit ? 'Отправить менеджеру' : 'Отправить как есть' }}</button>
                </form>
                @endif
            </div>
        @endif
        {{-- Подписанный — кнопкой словами, а не скрепкой в строке (19.11.2026, владелец: «тупо иконка скрепки — непонятно»): до отправки —
             серой во всю ширину под выбором (подписали вне системы), после — лаймовой рядом с «Отозвать». «Отозвать» — по своей
             ширине, остальное — подписанному: поровну на телефоне «Приложить подписанный» вылезал за кнопку (23.11.2026). --}}
        @if (! $sent && $canAttach && $staff)
            <div class="send-under"><x-deal.attach-signed :deal="$deal" :base="$base" wide/></div>
        @elseif ($sent && ($canAttach || $canRecall))
            <div class="send-under send-pair">
                @if ($canAttach)<x-deal.attach-signed :deal="$deal" :base="$base" primary/>@endif
                @if ($canRecall)
                    <form method="post" action="{{ $base }}/contract/recall" class="contents" data-turbo-confirm="Отозвать {{ mb_strtolower(mb_substr($title, 0, 1)).mb_substr($title, 1) }}? Менеджер перестанет его видеть{{ $edited ? ', заполненный файл удалится' : '' }}">
                        @csrf
                        <button type="submit" class="btn btn-quiet btn-s !flex-none !px-4">Отозвать</button>
                    </form>
                @endif
            </div>
        @endif
        @if ($canEdit)<x-deal.contract-fill-sheet :id="$sheet" :base="$base" :name="$name"/>@endif
    </div>
@endif
