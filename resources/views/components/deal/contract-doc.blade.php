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
     - отправлен — под строкой «Приложить подписанный» | «Отозвать» (`ShowToManager::recallContract`); отправлен ли — словом подписи
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
    <div class="contents" @if ($canEdit) data-controller="sheet" @endif>
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
                    <button type="button" class="btn btn-accent btn-s gap-2" data-action="sheet#open" aria-controls="{{ $sheet }}"><x-ui.icon name="edit" class="size-4"/>Заполнить…</button>
                @endif
                <form method="post" action="{{ $base }}/contract/show" class="contents">
                    @csrf
                    <button type="submit" @class(['btn btn-s gap-2', 'btn-quiet' => $canEdit && ! $edited, 'btn-accent' => ! ($canEdit && ! $edited)])><x-ui.icon name="send" class="size-4"/>{{ $edited || ! $canEdit ? 'Отправить менеджеру' : 'Отправить как есть' }}</button>
                </form>
            </div>
        @endif
        {{-- Подписанный — кнопкой словами, а не скрепкой в строке (19.11.2026, владелец: «тупо иконка скрепки — непонятно»): до отправки —
             серой во всю ширину под выбором (подписали вне системы), после — лаймовой рядом с «Отозвать». --}}
        @if (! $sent && $canAttach && $staff)
            <div class="send-under"><x-deal.attach-signed :deal="$deal" :base="$base" wide/></div>
        @elseif ($sent && ($canAttach || $canRecall))
            <div class="send-under send-pair">
                @if ($canAttach)<x-deal.attach-signed :deal="$deal" :base="$base" primary/>@endif
                @if ($canRecall)
                    <form method="post" action="{{ $base }}/contract/recall" class="contents" data-turbo-confirm="Отозвать {{ mb_strtolower(mb_substr($title, 0, 1)).mb_substr($title, 1) }}? Менеджер перестанет его видеть">
                        @csrf
                        <button type="submit" class="btn btn-quiet btn-s">Отозвать</button>
                    </form>
                @endif
            </div>
        @endif
        @if ($canEdit)
            <x-ui.sheet :id="$sheet" title="Заполнить договор">
                <form method="post" action="{{ $base }}/contract/edited" enctype="multipart/form-data" class="flex flex-col gap-4">
                    @csrf
                    <input type="hidden" name="_sheet" value="{{ $sheet }}">
                    <div class="edit-step">
                        <span class="edit-step-n">1</span>
                        <div class="min-w-0 flex-1">
                            <div>Скачайте Word и заполните</div>
                            {{-- Строкой документа сделки (`x-deal.doc-row`, как «Счёт за ТС» рядом; 20.11.2026, владелец: чип «⤓ ДКП … .docx» — «уродство»). --}}
                            <div class="list mt-2">
                                <x-deal.doc-row :href="$base.'/dkp.docx?edit=1'" :title="$name" :name="$name.'.docx'" mime="application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                                    <x-slot:dl><a href="{{ $base }}/dkp.docx?edit=1" download class="btn btn-ghost btn-s px-1.5 text-ink-muted" aria-label="Скачать" title="Скачать" data-turbo="false" data-turbo-prefetch="false" data-controller="file" data-action="file#share" data-file-name="{{ $name }}.docx"><x-ui.icon name="download" class="size-5"/></a></x-slot:dl>
                                </x-deal.doc-row>
                            </div>
                        </div>
                    </div>
                    <div class="edit-step">
                        <span class="edit-step-n">2</span>
                        <div class="min-w-0 flex-1">
                            <div>Приложите заполненный</div>
                            {{-- Вид поля, как `x-ui.file-field` (системного «Choose File» нет), со своим id: на странице полей файла много. --}}
                            <div class="field mt-2 @error('file') field-invalid @enderror" data-controller="file-name">
                                <label for="{{ $sheet }}-file" class="field-input flex cursor-pointer items-center gap-2">
                                    <x-ui.icon name="clip" class="size-4 shrink-0 text-ink-dim"/>
                                    <span class="min-w-0 truncate text-ink-muted" data-file-name-target="label">Выбрать файл .docx или .pdf</span>
                                </label>
                                <input id="{{ $sheet }}-file" name="file" type="file" accept="{{ ContractEdit::ACCEPT }}" required class="sr-only" data-action="change->file-name#show">
                                @error('file')<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>
                    <x-ui.button type="submit" class="w-full">Сохранить</x-ui.button>
                </form>
            </x-ui.sheet>
        @endif
    </div>
@endif
