import { Controller } from '@hotwired/stimulus';

// Деньги при вознаграждении менеджеру: поле с разделителями (на сервер — число) и суммы,
// пересчитанные на месте. ours — «нам остаётся» (разница минус вознаграждение), due —
// сколько менеджер отдаёт (база минус вознаграждение); ушло в минус — подпись dueLabel
// меняется на data-negative: тогда отдаём мы. payout — сколько выплатим менеджеру, когда за машину из гаража платит
// его покупатель: его расходы (spent) плюс вознаграждение.
export default class extends Controller {
    static targets = ['display', 'amount', 'ours', 'oursLabel', 'due', 'dueLabel', 'payout', 'oursOnly', 'dkpOnly', 'ownerDisplay', 'ownerAmount', 'offset', 'payee'];
    static values = { margin: Number, base: Number, spent: Number, amount: Number, cost: Number };

    connect() {
        this.input();
    }

    money(v) {
        const kopecks = Math.round(Math.abs(v) * 100) % 100 !== 0;
        return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: kopecks ? 2 : 0, maximumFractionDigits: 2 }).format(v) + ' ₽';
    }

    // «За ТС платят»: ПРАЙМ — разница и режим; ДКП и страховой — сколько им, нам — подбор (05.10.2026).
    // Схема, где покупатель платит не нам (ДКП, страховой): сумма ему, нам — подбор.
    get dkp() {
        return this.element.querySelector('input[name=scheme]:checked')?.dataset.selection === '1';
    }

    scheme() {
        const picked = this.element.querySelector('input[name=scheme]:checked');
        if (this.hasPayeeTarget && picked?.dataset.payee) this.payeeTarget.textContent = picked.dataset.payee;
        this.oursOnlyTargets.forEach((el) => { el.hidden = this.dkp; });
        this.dkpOnlyTargets.forEach((el) => { el.hidden = !this.dkp; });
        if (this.hasOursLabelTarget) this.oursLabelTarget.textContent = this.dkp ? this.oursLabelTarget.dataset.dkp : this.oursLabelTarget.dataset.ours;
        this.input();
    }

    input() {
        const digits = this.displayTarget.value.replace(/\D/g, '');
        const value = digits ? Number(digits) : 0;
        this.displayTarget.value = digits ? new Intl.NumberFormat('ru-RU').format(value) : '';
        this.amountTarget.value = digits ? value : '';
        if (this.hasOwnerDisplayTarget) {
            const ownerDigits = this.ownerDisplayTarget.value.replace(/\D/g, '');
            const owner = ownerDigits ? Number(ownerDigits) : 0;
            this.ownerDisplayTarget.value = ownerDigits ? new Intl.NumberFormat('ru-RU').format(owner) : '';
            this.ownerAmountTarget.value = ownerDigits ? owner : '';
            const offset = this.hasCostValue ? this.costValue - owner : 0;
            if (this.hasOffsetTarget) this.offsetTarget.textContent = owner && offset > 0 ? 'Взаимозачёт ' + this.money(offset) : '';
            if (this.dkp && this.hasOursTarget) {
                const ours = this.amountValue - owner - value;
                this.oursTarget.textContent = this.money(ours);
                this.oursTarget.classList.toggle('text-danger', ours < 0);
                return;
            }
        }
        if (this.hasOursTarget && this.hasMarginValue) {
            const ours = this.marginValue - value;
            this.oursTarget.textContent = this.money(ours);
            this.oursTarget.classList.toggle('text-danger', ours < 0);
        }
        if (this.hasDueTarget && this.hasBaseValue) {
            const due = this.baseValue - value;
            this.dueTarget.textContent = this.money(Math.abs(due));
            if (this.hasDueLabelTarget) this.dueLabelTarget.textContent = due < 0 ? this.dueLabelTarget.dataset.negative : this.dueLabelTarget.dataset.positive;
        }
        if (this.hasPayoutTarget) this.payoutTarget.textContent = this.money(this.spentValue + value);
    }
}
