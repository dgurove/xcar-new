{{-- Скан паспорта над полями (11.10.2026, владелец: «пусть прикрепляют скан, распознаётся, потом поправить в полях»):
     две плитки — разворот с фото и прописка. Пустая плитка — нажатие выбирает файл (камера или файл); со сканом — открывает
     наш просмотр на весь экран, обе страницы (07.11.2026, владелец: «пока корректирую распознанное, быстро посмотреть, что в
     паспорте написано»), заменить — круглой кнопкой в углу. Пока читаем — искра крутится, прочитанное встаёт в поля формы
     `form` с подсветкой (`passport_scan_controller`). party — контрагент покупателя; prefix — имя набора полей (`buyer[...]`). --}}
@props(['party', 'form', 'prefix' => 'buyer'])
@php
    $pages = [];
    foreach (\App\Billing\Party::PASSPORT_PAGES as $page => $label) {
        $m = $party?->passportScan($page);
        $v = $m?->updated_at?->timestamp;
        $pages[$page] = ['label' => $label, 'media' => $m, 'state' => $m?->getCustomProperty('read', 'done'),
            'url' => $m ? '/files/'.$m->id.'?v='.$v : null, 'thumb' => $m ? '/files/'.$m->id.'?thumb=1&v='.$v : null];
    }
@endphp
@if ($party)
<div class="passport-scan" data-controller="passport-scan" data-passport-scan-url-value="/parties/{{ $party->id }}/passport" data-passport-scan-form-value="{{ $form }}" data-passport-scan-prefix-value="{{ $prefix }}">
    @foreach ($pages as $page => $p)
        <div @class(['passport-tile', 'passport-tile--on' => $p['media'], 'spark-busy' => $p['state'] === 'reading']) data-passport-scan-target="tile" data-page="{{ $page }}" data-state="{{ $p['state'] ?? 'none' }}" @if ($p['media']) data-thumb="{{ $p['thumb'] }}" data-url="{{ $p['url'] }}" @endif>
            {{-- Со сканом — просмотр; пустая — выбор файла. PDF из двух страниц или две фотографии сразу — на обе плитки (19.10.2026). --}}
            <button type="button" class="passport-tile-img passport-tile-view" data-action="passport-scan#view" aria-label="Посмотреть: {{ $p['label'] }}">
                @if ($p['media'])<img src="{{ $p['thumb'] }}" alt="">@endif
            </button>
            <label class="passport-tile-img passport-tile-pick">
                <input type="file" class="sr-only" accept="image/*,application/pdf" multiple data-action="change->passport-scan#upload">
                <x-ui.icon name="camera" class="size-6"/>
            </label>
            <label class="passport-tile-swap" aria-label="Заменить: {{ $p['label'] }}">
                <input type="file" class="sr-only" accept="image/*,application/pdf" multiple data-action="change->passport-scan#upload">
                <x-ui.icon name="camera" class="size-4"/>
            </label>
            <span class="passport-tile-label">{{ $p['label'] }}</span>
            {{-- Что с файлом — по `data-state`: искра, пока читаем, «не прочитано» — если не вышло. --}}
            <span class="passport-tile-state"><x-ui.spark class="scan-spark size-4"/><span class="scan-failed">не прочитано</span></span>
        </div>
    @endforeach
</div>
@endif
