{{-- Готовый договор строкой документа (16.11.2026, владелец: «договор над „Подписанным договором“»): в «Продаже» CRM — сразу над
     ним (`x-deal.money`), в окне документов — в карточке «Договор» (`x-deal.contract`). Только готовый (15.11.2026, владелец:
     «ЗАЧЕМ мы показываем договор, если он НЕ ГОТОВ»): чего не хватает — строками `x-deal.contract-gaps`.
     Вариант Б (19.11.2026, владелец выбрал из макетов):
     - договор — значком Word (он и есть исходник для правки), скачивание спрашивает формат: Word или PDF (`x-deal.dkp-download`);
     - не отправлен — под строкой «Отправить как есть» и «Исправить…»: шторка «Исправить договор» — 1) скачать Word (с номером
       договора, `dkp.docx?edit=1`), 2) приложить исправленный (`EditContract`);
     - приложен исправленный — метка «исправлен», справа ↶ «Вернуть сформированный», под строкой одна «Отправить менеджеру»;
       данные сделки поменялись после правки — красным (`ContractEdit::stale`);
     - отправлен — «у менеджера с …», справа «Отозвать» (`ShowToManager::recallContract`).
     `attach` — скрепкой справа приложить подписанный (18.11.2026). --}}
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
    <div class="contents" @if ($canEdit) data-controller="sheet" @endif>
        <x-deal.doc-row :href="$base.'/dkp.pdf'" :title="$title" :name="$isPdf ? 'dkp.pdf' : 'dkp.docx'" :bytes="$original?->size" :file="$name.'.pdf'" id="dkp-row-{{ $deal->id }}">
            @if ($edited)<span class="tag">исправлен</span>@endif
            @if ($sent)<span class="text-ink-muted">у менеджера с {{ \App\Support\Ago::day($contract->shown_at) }}</span>@else<span class="text-ink-muted">не отправлен</span>@endif
            @if ($stale)<span class="text-danger">данные сделки менялись после правки</span>@endif
            <x-slot:dl><x-deal.dkp-download :deal="$deal" :base="$base" :name="$name" :word="! $isPdf"/></x-slot:dl>
            @if ($canAttach || ($edited && ! $sent && $staff) || $canRecall)
                <x-slot:acts>
                    @if ($edited && ! $sent && $staff)
                        <form method="post" action="{{ $base }}/contract/edited" class="contents" data-turbo-confirm="Вернуть сформированный договор? Исправленный файл удалится">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-ghost btn-s px-1.5 text-ink-muted" title="Вернуть сформированный" aria-label="Вернуть сформированный"><x-ui.icon name="undo" class="size-5"/></button>
                        </form>
                    @endif
                    @if ($canAttach)
                    <form method="post" action="{{ $base }}/signed" enctype="multipart/form-data" class="contents" data-controller="autosubmit">
                        @csrf
                        <label class="btn btn-ghost btn-s px-2 text-ink-muted" title="Приложить подписанный" aria-label="Приложить подписанный">
                            <input type="file" name="files[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.heic,.heif,.doc,.docx" class="sr-only" data-action="change->autosubmit#submit"><x-ui.icon name="clip" class="size-5"/>
                        </label>
                    </form>
                    @endif
                    @if ($canRecall)<x-deal.recall-row :deal="$deal" :action="$base.'/contract/recall'" :what="mb_strtolower(mb_substr($title, 0, 1)).mb_substr($title, 1)"/>@endif
                </x-slot:acts>
            @endif
        </x-deal.doc-row>
        {{-- Не отправлен — выбор под строкой: как есть или через Word. Исправленный — одна «Отправить менеджеру». --}}
        @if (! $sent && $staff && $deal->buyer_id)
            <div class="send-under send-pair">
                <form method="post" action="{{ $base }}/contract/show" class="contents">
                    @csrf
                    <button type="submit" class="btn btn-accent btn-s gap-2"><x-ui.icon name="send" class="size-4"/>{{ $edited ? 'Отправить менеджеру' : 'Отправить как есть' }}</button>
                </form>
                @if ($canEdit && ! $edited)
                    <button type="button" class="btn btn-quiet btn-s gap-2" data-action="sheet#open" aria-controls="{{ $sheet }}"><x-ui.icon name="edit" class="size-4"/>Исправить…</button>
                @endif
            </div>
        @endif
        @if ($canEdit)
            <x-ui.sheet :id="$sheet" title="Исправить договор">
                <form method="post" action="{{ $base }}/contract/edited" enctype="multipart/form-data" class="flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="_sheet" value="{{ $sheet }}">
                    <div class="edit-step">
                        <span class="edit-step-n">1</span>
                        <div class="min-w-0 flex-1">
                            <div>Скачайте Word и поправьте</div>
                            <a href="{{ $base }}/dkp.docx?edit=1" download class="chip mt-2 max-w-full" data-turbo="false" data-turbo-prefetch="false" data-controller="file" data-action="file#share" data-file-name="{{ $name }}.docx">
                                <x-ui.icon name="download" class="size-4 shrink-0"/><span class="truncate">{{ $name }}.docx</span>
                            </a>
                        </div>
                    </div>
                    <div class="edit-step">
                        <span class="edit-step-n">2</span>
                        <div class="min-w-0 flex-1">
                            <div>Приложите исправленный</div>
                            <input type="file" name="file" accept="{{ ContractEdit::ACCEPT }}" required class="field-input mt-2 w-full">
                            @error('file')<p class="field-error mt-1">{{ $message }}</p>@enderror
                        </div>
                    </div>
                    <x-ui.button type="submit" class="w-full">Сохранить</x-ui.button>
                </form>
            </x-ui.sheet>
        @endif
    </div>
@endif
