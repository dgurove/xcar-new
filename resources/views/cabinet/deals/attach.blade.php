{{-- Кнопка «Приложить» шага, что просит документ (19.10.2026, владелец: «серая кнопка — неочевидно, что приложить
     обязательно»): пока файла нет — главная кнопка шага, лаймовая во всю ширину, со словами что приложить; приложили —
     тихой строкой «Приложить ещё», а главной становится кнопка шага («Договор приложен»). Меняется потоком вместе с
     файлами (`files-stream`). --}}
@php
    $has = $requirement->getMedia('files')->isNotEmpty();
    // Что приложить — словами шага: подписанный договор, платёжка (оплата поставщику), иначе документ.
    $stage = $requirement->stage;
    $attachLabel ??= match (true) {
        (bool) preg_match('/договор/iu', $stage?->name.' '.$requirement->title) => 'Приложить договор',
        (bool) $stage?->isPayStep() || (bool) preg_match('/плат[её]ж/iu', (string) $requirement->title) => 'Приложить платёжку',
        default => 'Приложить документ',
    };
@endphp
<div id="requirement-attach">
    @if ($has)
        <button type="button" class="retreat-link mt-1 flex items-center justify-center gap-1.5" data-action="photos#pick"><x-ui.icon name="clip" class="size-4"/>Приложить ещё</button>
    @else
        <x-ui.button type="button" block data-action="photos#pick"><x-ui.icon name="clip" class="size-5"/> {{ $attachLabel }}</x-ui.button>
    @endif
</div>
