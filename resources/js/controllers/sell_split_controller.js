import { Controller } from '@hotwired/stimulus';
import { rub, rubles } from '../lib/money';
import { garageReward } from '../lib/garage-share';

// Расчёт продажи гаражной Совкомбанка по ходу набора цены (`garage.cars.sell-split`, 12.11.2026, владелец: считали на
// калькуляторе в WhatsApp). Строки стоят сразу — до цены тусклое «—», шторка не растёт; после ошибки цены пересчитываются
// из поля при подключении. Цифры те же, что запишет сервер (`Deal::sellSplit`).
export default class extends Controller {
    static targets = ['price', 'reward', 'payable', 'rest', 'restLabel'];
    static values = { cost: Number, spent: Number, share: Number, paid: Number };

    connect() {
        this.update();
    }

    update() {
        const p = this.hasPriceTarget ? rubles(this.priceTarget.value) : null;
        const reward = p ? garageReward(p - this.costValue, this.spentValue, this.shareValue) : null;
        const payable = p ? p - reward : null;
        const sign = this.rewardTarget.dataset.sign ?? '';
        this.show(this.rewardTarget, p ? `${sign}${rub(reward)}` : null);
        // Вознаграждение менеджеру — зелёным, пока есть число; «—» — тусклым.
        if (sign) this.rewardTarget.classList.toggle('profit-sum', Boolean(p));
        this.show(this.payableTarget, p ? rub(payable) : null);
        if (!this.hasRestTarget) return;
        const rest = p ? payable - this.paidValue : null;
        const done = rest !== null && Math.abs(rest) < 1;
        this.restLabelTarget.textContent = done ? 'Всё оплачено' : rest !== null && rest < 0 ? 'Переплата' : 'Осталось оплатить';
        this.restLabelTarget.classList.toggle('text-open', done);
        this.show(this.restTarget, rest === null ? null : done ? '' : rub(Math.abs(rest)));
    }

    show(el, text) {
        el.textContent = text ?? '—';
        el.classList.toggle('text-ink-dim', text === null);
    }
}
