import { Controller } from '@hotwired/stimulus';
import { floorFrom, markup } from '../lib/floor';
import { nums, rub, rubles } from '../lib/money';

// Оценочная → закупочная прямо в форме, по правилу сервера (`Offers\Pricing`, ступени — значением `rules`). Закупочная
// пересчитывается, только пока она пустая или посчитана от прежней оценочной — вписанную рукой не трогаем. Ступени
// предложения правят диапазоны (x-offer.floor-rules, событие floor-rules:change — `rules`). Закупочная бывает и не полем,
// а итогом (`result`, у партнёра): тогда она всегда по ступеням. У партнёра ещё (08.11.2026): `rule` — наценка ступени, где
// лежит оценочная («+10%»; пусто — все диапазоны строкой с сервера), `button` — подпись кнопки «Отправить цену» / «Сохранить»
// (`data-ready` / `data-idle`): цена уходит, только когда оценочная вписана.

export default class extends Controller {
    static targets = ['value', 'floor', 'result', 'rule', 'button'];
    static values = { rules: Array };

    // Оценочной в форме может не быть (не Альфа, или она сверху карточки) — тогда считать нечего.
    connect() {
        this.was = this.hasValueTarget ? (rubles(this.valueTarget.value) ?? 0) : 0;
        this.ruleIdle = this.hasRuleTarget ? this.ruleTarget.textContent : '';
        this.show();
    }

    sync() {
        this.apply(this.rulesValue);
    }

    rules(e) {
        this.apply(e.detail.rules);
    }

    apply(rules) {
        const wasRules = this.rulesValue;
        this.rulesValue = rules;
        this.show();
        if (!this.hasValueTarget || !this.hasFloorTarget) return;
        const value = rubles(this.valueTarget.value) ?? 0;
        const floor = rubles(this.floorTarget.value) ?? 0;
        const auto = !floor || (this.was && floor === floorFrom(this.was, wasRules));
        this.was = value;
        if (!value || !auto) return;
        this.floorTarget.value = nums(floorFrom(value, rules));
        this.floorTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    show() {
        if (!this.hasValueTarget) return;
        const value = rubles(this.valueTarget.value) ?? 0;
        if (this.hasResultTarget) this.resultTarget.textContent = value ? rub(floorFrom(value, this.rulesValue)) : '';
        if (this.hasRuleTarget) this.ruleTarget.textContent = value ? markup(value, this.rulesValue, rub).replace('%', ' %') : this.ruleIdle;
        this.buttonTargets.forEach((b) => { b.textContent = value ? b.dataset.ready : b.dataset.idle; });
    }
}
