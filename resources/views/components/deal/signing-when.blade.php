{{-- Когда покупатель сможет подписать договор — дни и время, по нескольку (`Offers\Signing`, 11.11.2026). Пока поставщик готовит
     договор: не названо — выбор в два шага (`cabinet.deals.signing-when-form`); названо — строкой «Покупатель может чт 12.11
     или пт 13.11, 11:00 или 15:00» с «Изменить» (шторка с тем же выбором). На шаге выбора — сразу выбор, «Записать».
     `readonly` — только строка (письмо записи ушло). `action` — POST (сайт или CRM), `back` — куда вернуться (чат). --}}
@props(['deal', 'action' => null, 'back' => null, 'picks' => false, 'readonly' => false])
@php $line = $picks ? null : \App\Offers\Signing::wishLine($deal); @endphp
<div {{ $attributes }}>
    @if ($line && $readonly)
        <div class="info-row"><x-ui.icon name="calendar" class="info-icon size-5"/><span class="min-w-0 flex-1">Просим записать: {{ $line }}</span></div>
    @elseif ($line)
        <div data-controller="sheet">
            <button type="button" class="info-row w-full text-left" data-action="sheet#open">
                <x-ui.icon name="calendar" class="info-icon size-5"/>
                <span class="min-w-0 flex-1">Покупатель может {{ $line }}</span>
                <span class="text-sm text-ink-muted">Изменить</span>
            </button>
            <x-ui.sheet :id="'signing-when-'.$deal->id" title="Когда покупатель сможет подписать">
                @include('cabinet.deals.signing-when-form', ['submit' => 'Сохранить'])
            </x-ui.sheet>
        </div>
    @elseif (! $readonly)
        @include('cabinet.deals.signing-when-form', ['submit' => $picks ? 'Записать' : 'Отправить'])
    @endif
</div>
