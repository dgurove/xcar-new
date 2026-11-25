import { Controller } from '@hotwired/stimulus';
import { nums, rubles } from '../lib/money';
import { confirmSheet } from '../confirm';

// Сколько на счёт за ТС, остальное — ссылкой (25.11.2026, `x-deal.invoice-split`): полоса остатка (цена − закупочная − вознаграждение −
// наличные) и два поля — счёт целиком и ссылкой; любое двигает другие. Цена, вознаграждение и наличные — поля той же формы: их
// правка не трогает вписанный счёт (ссылка забирает разницу, в минус не уходит: в форму идёт не больше цены минус вознаграждение и
// наличные, а вписанное помнится — цену стёрли и вписали заново, счёт тот же), а не вписанный идёт за умолчанием — Р.О.С. закупочная,
// ПРАЙМ всё в счёте. Меньше закупочной — как вписано: ошибку словами даёт сервер. У Совкомбанка ссылка из нуля спрашивает «Вы
// уверены?» по концу ввода (`change`), «Ещё подумаю» возвращает всё в счёт.
export default class extends Controller {
    static targets = ['bar', 'range', 'car', 'link', 'hidden', 'rest', 'carText', 'linkText'];
    static values = { cost: Number, car: Number, ros: Boolean, set: Boolean, ask: String, title: String, ok: String, cancel: String };

    connect() {
        this.form = this.element.closest('form');
        this.pinned = this.setValue;
        this.car = this.carValue;
        this.agreed = this.cap - this.effective > 0;
        this.onForm = (e) => { if (!this.element.contains(e.target)) this.follow(); };
        this.form?.addEventListener('input', this.onForm);
        this.follow();
    }

    disconnect() {
        this.form?.removeEventListener('input', this.onForm);
    }

    // Цена продажи, вознаграждение (скрытое поле полосы менеджера) и наличные — из той же формы.
    get cap() {
        const v = (name) => rubles(this.form?.querySelector(`[name="${name}"]`)?.value) ?? 0;
        return Math.max(0, v('amount') - v('commission') - v('cash_part'));
    }

    // Закупочная — полем той же шторки (25.11.2026), нет поля — какой была.
    get cost() {
        const field = this.form?.querySelector('[name="cost"]');
        return field ? (rubles(field.value) ?? this.costValue) : this.costValue;
    }

    get fallback() {
        return this.rosValue ? this.cost : this.cap;
    }

    // Что уходит в форму: вписанное (не больше цены минус вознаграждение и наличные) или умолчание.
    get effective() {
        return this.pinned ? Math.min(this.cap, this.car) : this.fallback;
    }

    follow() {
        this.paint();
    }

    typedCar() {
        const car = rubles(this.carTarget.value);
        if (car === null) {
            this.pinned = false;
            return this.paint('car');
        }
        this.pin(car, 'car');
    }

    typedLink() {
        this.pin(this.cap - (rubles(this.linkTarget.value) ?? 0), 'link');
    }

    slide() {
        const p = Number(this.rangeTarget.value);
        const rest = this.cap - this.cost;
        const car = p >= 100 ? this.cap : this.cost + Math.round(rest * p / 100 / 1000) * 1000;
        this.pin(Math.min(this.cap, car), 'range');
    }

    pin(car, from) {
        this.pinned = true;
        this.car = car;
        this.paint(from);
    }

    // Конец ввода: ссылка у Совкомбанка появилась — спросить.
    async check() {
        this.paint();
        if (!this.askValue || this.agreed || this.cap - this.effective <= 0) return;
        const yes = await confirmSheet(this.titleValue, { text: this.askValue, label: this.okValue, cancel: this.cancelValue });
        if (yes) {
            this.agreed = true;
            return;
        }
        this.pinned = false;
        this.paint();
    }

    paint(from = null) {
        const cap = this.cap;
        const cost = this.cost;
        const rest = cap - cost;
        const car = this.effective;
        const shown = Math.max(cost, Math.min(cap, car));
        const link = Math.max(0, cap - car);
        if (from !== 'car') this.carTarget.value = nums(car);
        if (from !== 'link') this.linkTarget.value = nums(link);
        this.hiddenTarget.value = car;
        this.restTarget.textContent = nums(Math.max(0, rest));
        const p = rest > 0 ? (shown - cost) / rest * 100 : 0;
        this.barTarget.hidden = rest <= 0;
        this.barTarget.style.setProperty('--p', `${p}%`);
        if (from !== 'range') this.rangeTarget.value = String(Math.round(p));
        this.carTextTargets.forEach((el) => { el.textContent = `на счёт +${nums(shown - cost)}`; });
        this.linkTextTargets.forEach((el) => { el.textContent = `по ссылке ${nums(Math.max(0, cap - shown))}`; });
    }
}
