import { Controller } from '@hotwired/stimulus';

// Деньги при вознаграждении менеджеру: поле с разделителями (на сервер — число) и суммы,
// пересчитанные на месте. ours — «нам остаётся» (разница минус вознаграждение), due —
// сколько менеджер отдаёт (база минус вознаграждение); ушло в минус — подпись dueLabel
// меняется на data-negative: тогда отдаём мы.
export default class extends Controller {
    static targets = ['display', 'amount', 'ours', 'due', 'dueLabel'];
    static values = { margin: Number, base: Number };

    connect() {
        this.input();
    }

    money(v) {
        const kopecks = Math.round(Math.abs(v) * 100) % 100 !== 0;
        return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: kopecks ? 2 : 0, maximumFractionDigits: 2 }).format(v) + ' ₽';
    }

    input() {
        const digits = this.displayTarget.value.replace(/\D/g, '');
        const value = digits ? Number(digits) : 0;
        this.displayTarget.value = digits ? new Intl.NumberFormat('ru-RU').format(value) : '';
        this.amountTarget.value = digits ? value : '';
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
    }
}
