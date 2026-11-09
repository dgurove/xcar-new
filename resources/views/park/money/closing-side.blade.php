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
                <x-mail.rail :messages="$letters->sortBy(fn ($m) => $m->date_at?->getTimestamp() ?? 0)->values()" :url="$lettersUrl"/>
            </x-ui.card>
        @endif
        @if ($showFiles)
            <x-ui.card title="Документы">
                <div class="list" data-docs-scope>
                    @foreach ($files as $f)
                        <x-ui.doc :doc="$f" class="row gap-3"><x-ui.file-icon :name="$f['name']"/><span class="min-w-0 flex-1 truncate">{{ $f['label'] }}</span><span class="shrink-0 truncate text-sm text-ink-muted">{{ pathinfo($f['name'], PATHINFO_FILENAME) }}</span></x-ui.doc>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    </div>
@endif
