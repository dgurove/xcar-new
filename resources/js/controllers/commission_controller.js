import { Controller } from '@hotwired/stimulus';
import { nums, rub, rubles } from '../lib/money';

// Деньги при вознаграждении менеджеру: поле с разделителями (на сервер — число) и суммы,
// пересчитанные на месте. ours — «нам остаётся» (разница минус вознаграждение), due —
// сколько менеджер отдаёт (база минус вознаграждение); ушло в минус — подпись dueLabel
// меняется на data-negative: тогда отдаём мы. payout — сколько выплатим менеджеру, когда за машину из гаража платит
// его покупатель: его расходы (spent) плюс вознаграждение. price — цену вписывают тут же («Отдать» без подтверждения):
// разница и «нам остаётся» идут за ней, не выше закупочной — форма переспрашивает и шлёт согласие loss.
export default class extends Controller {
    static targets = ['display', 'amount', 'ours', 'oursLabel', 'due', 'dueLabel', 'payout', 'oursOnly', 'dkpOnly', 'ownerDisplay', 'ownerAmount', 'offset', 'payee', 'price', 'margin', 'loss'];
    static values = { margin: Number, base: Number, spent: Number, amount: Number, cost: Number };

    connect() {
        this.input();
    }

    money(v) {
        const kopecks = Math.round(Math.abs(v) * 100) % 100 !== 0;
        return rub(v, kopecks ? 2 : 0);
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
        const priced = this.hasPriceTarget ? this.price() : true;
        this.sums();
        if (!priced) {
            if (this.hasOursTarget) this.oursTarget.textContent = '';
            if (this.hasMarginTarget) this.marginTarget.textContent = '';
        }
    }

    // Цена вписана здесь: от неё разница, а цена не выше закупочной — вопрос перед отправкой и согласие loss.
    price() {
        const p = rubles(this.priceTarget.value);
        this.amountValue = p ?? 0;
        if (this.hasCostValue) {
            this.marginValue = (p ?? 0) - this.costValue;
            if (this.hasMarginTarget) {
                this.marginTarget.textContent = this.money(this.marginValue);
                this.marginTarget.classList.toggle('text-danger', this.marginValue < 0);
            }
            const minus = p !== null && p <= this.costValue;
            if (this.hasLossTarget) this.lossTarget.value = minus ? '1' : '';
            if (minus) {
                this.element.dataset.turboConfirm = `Отдать за ${rub(p)} при закупочной ${rub(this.costValue)}?`;
                this.element.dataset.turboConfirmText = this.costValue > p ? `Сделка в минус ${rub(this.costValue - p)}` : 'Наценки нет';
            } else {
                delete this.element.dataset.turboConfirm;
                delete this.element.dataset.turboConfirmText;
            }
        }

        return p !== null;
    }

    sums() {
        const typed = rubles(this.displayTarget.value);
        const value = typed ?? 0;
        this.displayTarget.value = typed === null ? '' : nums(value);
        this.amountTarget.value = typed === null ? '' : value;
        if (this.hasOwnerDisplayTarget) {
            const ownerTyped = rubles(this.ownerDisplayTarget.value);
            const owner = ownerTyped ?? 0;
            this.ownerDisplayTarget.value = ownerTyped === null ? '' : nums(owner);
            this.ownerAmountTarget.value = ownerTyped === null ? '' : owner;
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
