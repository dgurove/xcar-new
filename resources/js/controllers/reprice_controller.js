import { Controller } from '@hotwired/stimulus';
import { nums, rubles } from '../lib/money';

// Шторка «Переоценка» (28.10.2026, `x-offer.reprice`): по каждому, кто подтвердил, и по сделке — «Оставить | Обновить»;
// заранее не отмечено ничего, «Применить» живая, когда решено по всем. Новая цена правится — «Обновить N» и «станет ниже
// закупочной» идут за ней; выше прежней — «Спросить N» (менеджеру вопрос). «Всем оставить / обновить» — одним нажатием (недоступное «Обновить» сделки не трогается).
export default class extends Controller {
    static targets = ['price', 'row', 'to', 'verb', 'loss', 'submit'];

    connect() {
        this.update();
    }

    get price() {
        return rubles(this.priceTarget.value);
    }

    all({ params: { choice } }) {
        for (const row of this.rowTargets) {
            const input = row.querySelector(`input[type=radio][value="${choice}"]:not(:disabled)`);
            if (input) input.checked = true;
        }
        this.update();
    }

    update() {
        const price = this.price;
        this.toTargets.forEach((el) => { el.textContent = price ? nums(price) : '—'; });
        // Выше прежней закупочной — не «Обновить», а «Спросить»: менеджеру уйдёт вопрос (`RepriceAsk`, 28.10.2026).
        this.verbTargets.forEach((el) => { el.textContent = price && price > Number(el.dataset.cost) ? 'Спросить' : 'Обновить'; });
        let ready = Boolean(price);
        for (const row of this.rowTargets) {
            const picked = row.querySelector('input[type=radio]:checked');
            if (!picked) ready = false;
            const amount = Number(row.dataset.amount) || 0;
            const loss = row.querySelector('[data-reprice-target="loss"]');
            const under = picked?.value === 'update' && price && amount && amount <= price;
            if (loss) {
                loss.hidden = !under;
                loss.textContent = under ? (price > amount ? `Станет ниже закупочной на ${nums(price - amount)}` : 'Станет равна закупочной') : '';
            }
        }
        if (this.hasSubmitTarget) this.submitTarget.disabled = !ready;
    }
}
