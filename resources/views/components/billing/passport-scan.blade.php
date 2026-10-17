{{-- Скан паспорта над полями (11.10.2026, владелец: «пусть прикрепляют скан, распознаётся, потом поправить в полях»):
     две плитки — разворот с фото и прописка; нажатие — камера или файл. Пока читаем — искра крутится, прочитанное
     встаёт в пустые поля формы `form` с подсветкой (`passport_scan_controller`), набранное руками не трогаем.
     party — контрагент покупателя; prefix — имя набора полей (`buyer[...]`). --}}
@props(['party', 'form', 'prefix' => 'buyer'])
@php
    $pages = [];
    foreach (\App\Billing\Party::PASSPORT_PAGES as $page => $label) {
        $m = $party?->passportScan($page);
        $pages[$page] = ['label' => $label, 'media' => $m, 'state' => $m?->getCustomProperty('read', 'done')];
    }
@endphp
@if ($party)
<div class="passport-scan" data-controller="passport-scan" data-passport-scan-url-value="/parties/{{ $party->id }}/passport" data-passport-scan-form-value="{{ $form }}" data-passport-scan-prefix-value="{{ $prefix }}">
    @foreach ($pages as $page => $p)
        <label @class(['passport-tile', 'passport-tile--on' => $p['media'], 'spark-busy' => $p['state'] === 'reading']) data-passport-scan-target="tile" data-page="{{ $page }}" data-state="{{ $p['state'] ?? 'none' }}">
            <input type="file" class="sr-only" accept="image/*,application/pdf" data-action="change->passport-scan#upload">
            <span class="passport-tile-img">
                @if ($p['media'])<img src="/files/{{ $p['media']->id }}?thumb=1" alt="" data-passport-scan-target="img">@else<x-ui.icon name="camera" class="size-6"/>@endif
            </span>
            <span class="passport-tile-label">{{ $p['label'] }}</span>
            {{-- Что с файлом — по `data-state`: искра, пока читаем, «не прочитано» — если не вышло. --}}
            <span class="passport-tile-state"><x-ui.spark class="scan-spark size-4"/><span class="scan-failed">не прочитано</span></span>
        </label>
    @endforeach
</div>
@endif
