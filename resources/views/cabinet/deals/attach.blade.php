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
        // Оплата — чек кассы, скан или файл платёжки (10.11.2026, владелец: «не всегда сфотографировать»).
        (bool) $stage?->isPayStep() || (bool) preg_match('/плат[её]ж/iu', (string) $requirement->title) => 'Приложить чек или платёжку',
        default => 'Приложить документ',
    };
    // Оплата — сначала камера: в кассе дают чек, его снимают (10.11.2026, владелец: «не прикрепляют чеки»), файл платёжки — строкой.
    $cheque = $attachLabel === 'Приложить чек или платёжку';
@endphp
<div id="requirement-attach">
    @if ($has)
        <button type="button" class="retreat-link mt-1 flex items-center justify-center gap-1.5" data-action="{{ $cheque ? 'photos#camera' : 'photos#pick' }}"><x-ui.icon name="{{ $cheque ? 'camera' : 'clip' }}" class="size-4"/>{{ $cheque ? 'Сфотографировать ещё' : 'Приложить ещё' }}</button>
        @if ($cheque)<button type="button" class="retreat-link -mt-2" data-action="photos#pick">Приложить файл платёжки</button>@endif
    @elseif ($cheque)
        <x-ui.button type="button" block data-action="photos#camera"><x-ui.icon name="camera" class="size-5"/> Сфотографировать чек</x-ui.button>
        <button type="button" class="retreat-link mt-1" data-action="photos#pick">или приложить файл платёжки</button>
    @else
        <x-ui.button type="button" block data-action="photos#pick"><x-ui.icon name="clip" class="size-5"/> {{ $attachLabel }}</x-ui.button>
    @endif
</div>
