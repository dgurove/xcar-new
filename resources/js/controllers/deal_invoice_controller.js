import { Controller } from '@hotwired/stimulus';
import { nums } from '../lib/money';

// Счёт по сделке: вид подставляет сумму (цена минус вознаграждение менеджера, подбор), счёт — одной строкой
// (18.11.2026). «Новый плательщик» раскрывает поля контрагента.
export default class extends Controller {
    static targets = ['kind', 'amount', 'party', 'newParty'];
    static values = { bases: Object };

    connect() {
        this.party();
    }

    kind() {
        const kind = this.kindTarget.value;
        if (this.basesValue[kind] !== undefined) this.amountTarget.value = this.basesValue[kind] ? nums(this.basesValue[kind]) : '';
        this.party();
    }

    party() {
        const fresh = this.partyTarget.value === 'new';
        this.newPartyTarget.hidden = !fresh;
        for (const field of this.newPartyTarget.querySelectorAll('input, select')) field.disabled = !fresh;
    }
}
