{{-- Плашки под шапкой документа (09.11.2026, владелец: «с вёрсткой пиздец, думай, как нормально переделать»): слева
     «Переписка» — письма веток документа строками (`x-mail.rail`), «Ответить» / «Написать» — в строке заголовка плашки;
     справа «Документы» — счёт и акт строками (шторка документов, `data-docs-scope`). Писем нет — одна плашка документов. --}}
@php
    $showFiles = $files && $d->state !== 'blocked';
    $talk = $letters->isNotEmpty();
    $lettersUrl = '/money/closing/'.$d->key.'/letters';
    $last = $letters->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->last();
    $ours = ! $last || ($last->isOurs() && ! $last->isForwardedByStaff());
@endphp
@if ($talk || $showFiles)
    <div @class(['doc-side mt-4 grid grid-cols-1 items-start gap-4', 'lg:grid-cols-[minmax(0,1fr)_22rem]' => $talk, 'max-w-md' => ! $talk])>
        @if ($talk)
            <x-ui.card title="Переписка" :count="\App\Mail\Chains\NodeTitle::count($letters)">
                <x-slot:actions><x-mail.window-button :url="$lettersUrl.'?reply=1'" :label="$ours ? 'Написать' : 'Ответить'" :icon="$ours ? 'mail' : 'reply'" class="btn-s btn-quiet"/></x-slot:actions>
                <x-mail.rail :messages="$letters" :url="$lettersUrl"/>
            </x-ui.card>
        @endif
        @if ($showFiles)
            @php $shown = $invoice ?? $draft; $canText = $shown && $shown->state !== \App\Billing\InvoiceState::Void && auth()->user()->canManagePark(); @endphp
            <x-ui.card title="Документы">
                {{-- Номер и дата акта, основание, строка счёта (17.11.2026, `closing-text`). --}}
                @if ($canText)
                    <x-slot:actions>
                        <span data-controller="sheet" class="contents">
                            <button type="button" class="btn btn-s btn-quiet" data-action="sheet#open"><x-ui.icon name="edit" class="size-4"/>Текст счёта</button>
                            <x-ui.sheet :id="'text-'.$d->key" title="Текст счёта">@include('park.money.closing-text', ['doc' => $d, 'shown' => $shown])</x-ui.sheet>
                        </span>
                    </x-slot:actions>
                @endif
                {{-- Строками прямо в плашке, без плашки внутри плашки и крупных значков (09.11.2026, владелец: «файлы громоздкие,
                     не вижу смысла блок в блоке»): значок типа мелко и имя файла, как оно уйдёт («Счет 20260911-01.pdf»). --}}
                <div class="doc-files" data-docs-scope>
                    @foreach ($files as $f)
                        <x-ui.doc :doc="$f" class="doc-file-row"><x-ui.file-icon :name="$f['name']" class="file-icon-s"/><span class="min-w-0 flex-1 truncate">{{ $f['name'] }}</span></x-ui.doc>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    </div>
@endif
