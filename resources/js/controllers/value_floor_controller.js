import { Controller } from '@hotwired/stimulus';

// Оценочная → закупочная прямо в форме, по тому же правилу, что сервер (`Park\Sale::floorFrom`): до 500 000 — плюс
// 50 000, до 2 000 000 — плюс 10 %, дальше плюс 200 000, вверх до тысячи. Закупочная пересчитывается, только пока она
// пустая или посчитана от прежней оценочной — вписанную рукой не трогаем.
export const floorFrom = (value) => {
    const price = value < 500_000 ? value + 50_000 : value < 2_000_000 ? value * 1.1 : value + 200_000;
    return Math.ceil(Math.round(price * 100) / 100 / 1000) * 1000;
};

const digits = (s) => Number(String(s ?? '').replace(/\D/g, '')) || 0;
const nums = (n) => n.toLocaleString('ru-RU').replace(/\s/g, ' ');

export default class extends Controller {
    static targets = ['value', 'floor'];

    connect() {
        this.was = digits(this.valueTarget.value);
    }

    sync() {
        const value = digits(this.valueTarget.value);
        const floor = digits(this.floorTarget.value);
        const auto = !floor || (this.was && floor === floorFrom(this.was));
        this.was = value;
        if (!value || !auto) return;
        this.floorTarget.value = nums(floorFrom(value));
        this.floorTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
