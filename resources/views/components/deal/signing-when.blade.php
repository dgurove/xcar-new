{{-- Когда покупатель сможет подписать договор (`Offers\Signing`, 11.11.2026). Не названо — сетка «день × час»
     (`x-deal.signing-slots`); названо — строкой «Покупатель может чт 12.11 в 12:00 или 14:00, пт 13.11 в 9:00» с «Изменить»,
     сетка раскрывается на месте. `picks` — шаг выбора: сразу сетка, «Записать». `readonly` — только строка (пишем поставщику).
     `action` — POST (сайт или CRM), `back` — куда вернуться. --}}
@props(['deal', 'action' => null, 'back' => null, 'picks' => false, 'readonly' => false, 'open' => false])
@php $line = $picks ? null : \App\Offers\Signing::wishLine($deal); @endphp
<div {{ $attributes }}>
    @if ($line && $readonly)
        <div class="info-row"><x-ui.icon name="calendar" class="info-icon size-5"/><x-deal.signing-wish-lines :deal="$deal" title="Просим записать"/></div>
    @elseif ($line)
        <details class="signing-edit">
            <summary class="info-row">
                <x-ui.icon name="calendar" class="info-icon size-5"/>
                <x-deal.signing-wish-lines :deal="$deal"/>
                <span class="signing-edit-open self-start text-sm text-ink-muted">Изменить</span>
                <span class="signing-edit-close self-start text-sm text-ink-muted">Свернуть</span>
            </summary>
            <x-deal.signing-slots :deal="$deal" :action="$action" :back="$back" submit="Сохранить" class="mt-3"/>
        </details>
    @elseif (! $readonly)
        <x-deal.signing-slots :deal="$deal" :action="$action" :back="$back" :submit="$picks ? 'Записать' : 'Отправить'"/>
    @endif
</div>
