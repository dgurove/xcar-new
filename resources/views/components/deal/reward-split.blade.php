{{-- Вознаграждение менеджера — ползунком, суммой или процентом от разницы (16.11.2026, начальник: «процент с продажи чтобы
     указывать можно было», «сумма или % выбрать», «он считает процент от разницы»; владелец выбрал из макетов вариант Г —
     ползунок сам заполняет полосу, подписанную «Менеджеру N» слева и «Подбор M» справа (услуги подбора, 18.11.2026), под ней «Менеджеру, ₽» и «% от
     разницы»: вписали — ползунок двигается сам; шаг ползунка 5 %, в полях — любое). Разница (`margin`) — от чего процент:
     цена минус закупочная, у старого гаража — прибыль. Её нет или она не больше нуля — одно поле суммы.
     `mode`: `reward` — в форму уходит вознаграждение (`commission`); `ours` — услуги подбора = разница минус вознаграждение
     (гаражная «платит менеджер», `deals.share`), тогда `value` — тоже услуги подбора. Хозяин формы (`commission`, `reprice`)
     держит поле суммы своими целями и действиями — атрибуты компонента ложатся на него; ползунок и процент шлют ему
     `input`. Разница меняется на лету — хозяин ставит `data-reward-margin-value` (`commission_controller#shareMargin`).
     `follow` — поле идёт за разницей процентом (предзаполнено долей менеджера `users.deal_share`), пока сумму не вписали
     руками; `share` — доля по умолчанию, когда суммы ещё нет; `showMargin` false — разница уже строкой выше (`money-form`). `hidden` — атрибуты скрытого поля (у `money-form` его цель `amount`). --}}
@props(['name', 'value' => null, 'margin' => null, 'mode' => 'reward', 'label' => 'Вознаграждение', 'id' => null,
    'hidden' => [], 'follow' => false, 'share' => null, 'showMargin' => true, 'placeholder' => '0', 'error' => null])
@php
    use App\Support\Money;
    $id ??= 'reward-'.uniqid();
    $margin = $margin === null ? null : (int) $margin;
    $value = $value === null || $value === '' ? null : (int) $value;
    // Вознаграждение на экране: у услуг подбора — разница минус они.
    $reward = $value === null ? null : ($mode === 'ours' && $margin !== null ? $margin - $value : $value);
    $split = $margin !== null && $margin > 0;
    $pct = $split && $reward !== null ? round($reward / $margin * 100, 1) : null;
    // Доля по умолчанию (`share`, %), а суммы ещё нет (цену впишут тут же) — процент стоит, сумма встанет за разницей.
    if ($reward === null && $share !== null) {
        [$pct, $follow] = [(float) $share, true];
    }
    $hostAction = trim((string) $attributes->get('data-action'));
    $error ??= $name;
@endphp
<div class="field reward-split @error($error) field-invalid @enderror" data-controller="reward" data-reward-mode-value="{{ $mode }}" @if ($margin !== null) data-reward-margin-value="{{ $margin }}" @endif @if ($follow) data-reward-follow-value="true" @endif>
    <div class="flex items-baseline justify-between gap-3">
        <label for="{{ $id }}" class="field-label">{{ $split ? $label : ($mode === 'ours' ? 'Услуги подбора, ₽' : $label.', ₽') }}</label>
        <span class="field-label nums" data-reward-target="margin" @unless ($split && $showMargin) hidden @endunless @unless ($showMargin) data-off @endunless>разница {{ $split ? Money::nums($margin) : '' }}</span>
    </div>
    <div class="reward-bar" data-reward-target="bar" style="--p: {{ $pct === null ? 0 : max(0, min(100, $pct)) }}%" @unless ($split) hidden @endunless>
        <div class="reward-bar-text" aria-hidden="true"><span data-reward-target="mgr"></span><span data-reward-target="ours"></span></div>
        <div class="reward-bar-fill" aria-hidden="true"><div class="reward-bar-text"><span data-reward-target="mgr"></span><span data-reward-target="ours"></span></div></div>
        <input type="range" min="0" max="100" step="5" value="{{ $pct === null ? 0 : (int) (round($pct / 5) * 5) }}" class="reward-bar-range" aria-label="Доля менеджера от разницы" data-reward-target="range" data-action="input->reward#slide">
    </div>
    <div @class(['grid gap-2', 'grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]' => $split]) data-reward-target="row">
        <div class="field">
            <label for="{{ $id }}" class="field-label" data-reward-target="rubLabel" @unless ($split) hidden @endunless>Менеджеру, ₽</label>
            <input id="{{ $id }}" type="text" inputmode="decimal" autocomplete="off" placeholder="{{ $placeholder }}"
                {{ $attributes->except('data-action')->class(['field-input nums']) }}
                value="{{ $split ? ($reward === null ? '' : Money::nums($reward)) : ($value === null ? '' : Money::nums($value)) }}"
                data-controller="digits" data-digits-decimals-value="0" data-reward-target="rub" data-action="input->digits#format input->reward#typed {{ $hostAction }}">
        </div>
        <div class="field" data-reward-target="pctBox" @unless ($split) hidden @endunless>
            <label for="{{ $id }}-pct" class="field-label">% от разницы</label>
            <input id="{{ $id }}-pct" type="text" inputmode="decimal" autocomplete="off" class="field-input nums" placeholder="0"
                value="{{ $pct === null ? '' : str_replace('.', ',', (string) $pct) }}" data-reward-target="pct" data-action="input->reward#percent">
        </div>
    </div>
    <input type="hidden" name="{{ $name }}" value="{{ $value }}" data-reward-target="hidden" {{ new \Illuminate\View\ComponentAttributeBag($hidden) }}>
    @error($error)<p class="field-error">{{ $message }}</p>@enderror
</div>
