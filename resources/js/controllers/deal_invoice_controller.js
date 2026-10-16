import { Controller } from '@hotwired/stimulus';
import { nums, rub } from '../lib/money';

// Счёт по сделке: вид подставляет базу (цена, разница, вознаграждение вендора),
// строки счёта показаны заранее — «Транспортное средство» база минус вознаграждение
// и «Агентское вознаграждение»; «вознаграждение от поставщика» идёт вендору одной строкой.
// «Новый плательщик» раскрывает поля контрагента.
export default class extends Controller {
    static targets = ['kind', 'amount', 'party', 'newParty', 'first', 'firstSum', 'fee', 'feeSum', 'total', 'withheld', 'due'];
    static values = { bases: Object, fee: Number, withheld: Boolean, vendorParty: Number };

    connect() {
        this.party();
        this.lines();
    }

    kind() {
        const kind = this.kindTarget.value;
        if (this.basesValue[kind] !== undefined) this.amountTarget.value = this.basesValue[kind] ? nums(this.basesValue[kind]) : '';
        if (kind === 'reward' && this.vendorPartyValue) this.partyTarget.value = String(this.vendorPartyValue);
        this.party();
        this.lines();
    }

    party() {
        const fresh = this.partyTarget.value === 'new';
        this.newPartyTarget.hidden = !fresh;
        for (const field of this.newPartyTarget.querySelectorAll('input, select')) field.disabled = !fresh;
    }

    lines() {
        const base = Number(String(this.amountTarget.value).replace(/[^\d.]/g, '')) || 0;
        const fee = this.kindTarget.value === 'reward' ? 0 : this.feeValue;
        this.firstSumTarget.textContent = this.money(base - fee);
        this.feeTarget.hidden = fee <= 0;
        this.feeSumTarget.textContent = this.money(fee);
        this.totalTarget.textContent = this.money(base);
        if (this.hasWithheldTarget) this.withheldTarget.hidden = !(fee > 0 && this.withheldValue);
        if (this.hasDueTarget) this.dueTarget.textContent = this.money(base - fee);
        this.firstTarget.classList.toggle('text-danger', base - fee < 0);
    }

    // Копейки — только если они есть (сумма из поля с точкой).
    money(value) {
        return rub(value, Number.isInteger(value) ? 0 : 2);
    }
}
