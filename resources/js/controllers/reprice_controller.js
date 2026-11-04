import { Controller } from '@hotwired/stimulus';
import { nums, rubles } from '../lib/money';

// Шторка «Переоценка» (28.10.2026, `x-offer.reprice`): по каждому, кто подтвердил, и по сделке — «Оставить | Обновить |
// Спросить» в любую сторону (03.11.2026, владелец: спрашивать менеджера — только решением админа); заранее не отмечено
// ничего, «Применить» живая, когда решено по всем. Новая цена правится — «→ N» и «станет ниже закупочной» идут за ней.
// «Всем оставить / обновить / спросить» — одним нажатием (недоступное у строки не трогается).
export default class extends Controller {
    static targets = ['price', 'row', 'to', 'loss', 'submit'];

    connect() {
        this.update();
    }

    get price() {
        return rubles(this.priceTarget.value);
    }

    // Чип правила переоценки («+50 000» / «+5%» к оценочной из письма) — закупочная в поле.
    pick({ params: { price } }) {
        this.priceTarget.value = nums(price);
        this.priceTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }

    all({ params: { choice } }) {
        for (const row of this.rowTargets) {
            // «Спросить» у того, кто разницы не видит, нет — ему «Обновить»: сервер так и сделал бы.
            const input = row.querySelector(`input[type=radio][value="${choice}"]:not(:disabled)`)
                ?? (choice === 'ask' ? row.querySelector('input[type=radio][value="update"]:not(:disabled)') : null);
            if (input) input.checked = true;
        }
        this.update();
    }

    update() {
        const price = this.price;
        this.toTargets.forEach((el) => { el.textContent = price ? nums(price) : '—'; });
        let ready = Boolean(price);
        for (const row of this.rowTargets) {
            const picked = row.querySelector('input[type=radio]:checked');
            if (!picked) ready = false;
            const amount = Number(row.dataset.amount) || 0;
            const loss = row.querySelector('[data-reprice-target="loss"]');
            const under = picked && picked.value !== 'keep' && price && amount && amount <= price;
            if (loss) {
                loss.hidden = !under;
                loss.textContent = under ? (price > amount ? `Станет ниже закупочной на ${nums(price - amount)}` : 'Станет равна закупочной') : '';
            }
        }
        if (this.hasSubmitTarget) this.submitTarget.disabled = !ready;
    }
}
