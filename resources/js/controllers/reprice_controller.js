import { Controller } from '@hotwired/stimulus';
import { nums, rubles } from '../lib/money';
import { ceilThousand } from '../lib/floor';

// Шторка «Переоценка» (28.10.2026, `x-offer.reprice`): по каждому, кто подтвердил, и по сделке — «Оставить | Обновить |
// Спросить» в любую сторону (03.11.2026, владелец: спрашивать менеджера — только решением админа); заранее не отмечено
// ничего, «Применить» живая, когда решено по всем. Новая цена правится — «→ N» и «станет ниже закупочной» идут за ней.
// «Всем оставить / обновить / спросить» — одним нажатием (недоступное у строки не трогается).
export default class extends Controller {
    static targets = ['price', 'asking', 'askingLoss', 'min', 'show', 'row', 'to', 'loss', 'submit', 'reward', 'share'];
    static values = { publish: Number, share: { type: Number, default: 0.6 } };

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

    // Доля менеджера от разницы (13.11.2026, `Deal::shareOf`): «Обновить» — от новой закупочной, иначе от прежней; поле
    // вознаграждения, равное прежней доле, идёт за ней, вписанное руками — нет.
    shareChip(price) {
        if (!this.hasShareTarget || !this.hasRewardTarget) return;
        const chip = this.shareTarget;
        const update = this.element.querySelector('input[name="deal"]:checked')?.value === 'update';
        const cost = update && price ? price : Number(chip.dataset.cost);
        const value = Math.max(0, Math.round((Number(chip.dataset.amount) - cost) * Number(chip.dataset.share) / 100));
        const typed = rubles(this.rewardTarget.value);
        if (this.lastShare === undefined) this.lastShare = Math.max(0, Math.round((Number(chip.dataset.amount) - Number(chip.dataset.cost)) * Number(chip.dataset.share) / 100));
        if (typed === this.lastShare && value !== typed) {
            this.rewardTarget.value = nums(value);
        }
        this.lastShare = value;
        chip.hidden = rubles(this.rewardTarget.value) === value;
        chip.textContent = `Менеджеру ${chip.dataset.share} % — ${nums(value)}`;
        chip.dataset.value = value;
    }

    share() {
        this.rewardTarget.value = nums(Number(this.shareTarget.dataset.value));
        this.lastShare = Number(this.shareTarget.dataset.value);
        this.shareTarget.hidden = true;
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
            // Владелец 04.11.2026: «пиши не „станет ниже закупочной“, а „закупочная станет выше цены продажи“».
            if (loss) {
                loss.hidden = !under;
                loss.textContent = under ? (price > amount ? `Закупочная станет выше цены продажи на ${nums(price - amount)}` : 'Закупочная станет равна цене продажи') : '';
            }
        }
        // Цена продажи не выше новой закупочной — словом под полем.
        if (this.hasAskingTarget && this.hasAskingLossTarget) {
            const asking = rubles(this.askingTarget.value);
            const low = Boolean(asking && price && asking <= price);
            this.askingLossTarget.hidden = !low;
            this.askingLossTarget.textContent = low ? (asking < price ? `Цена продажи ниже новой закупочной на ${nums(price - asking)}` : 'Цена продажи равна новой закупочной') : '';
        }
        // Пустая минимальная — серой подсказкой, как в «Ценах» (`Offer::minBid`): заявленная (пустая — новая закупочная вверх до
        // тысячи) плюс доля разницы до цены продажи.
        if (this.hasMinTarget) {
            const asking = this.hasAskingTarget ? rubles(this.askingTarget.value) : null;
            const from = this.publishValue || (price ? ceilThousand(price) : null);
            const min = asking && from && asking > from ? Math.round((from + (asking - from) * this.shareValue) / 1000) * 1000 : asking;
            this.minTarget.placeholder = min ? nums(min) : '';
        }
        this.shareChip(price);
        // «Пока думает — показать всем» — только когда по сделке выбрано «Спросить».
        if (this.hasShowTarget) {
            this.showTarget.hidden = this.element.querySelector('input[name="deal"]:checked')?.value !== 'ask';
        }
        if (this.hasSubmitTarget) this.submitTarget.disabled = !ready;
    }
}
