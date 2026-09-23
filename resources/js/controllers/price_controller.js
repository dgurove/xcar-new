import { Controller } from '@hotwired/stimulus';

// Шторка «Начислить»: цена из прайса подставляется при выборе вида — суммы, которые уже есть в прайсе,
// руками не набирают. Введённое человеком не перебиваем: меняем только пустое поле и то, что подставили сами.
export default class extends Controller {
    static targets = ['kind', 'price'];
    static values = { prices: Object };

    connect() {
        this.sync();
    }

    sync() {
        const price = this.pricesValue[this.kindTarget.value];
        const field = this.priceTarget;
        if (field.value && field.dataset.fromPrice !== '1') return;
        field.value = price ?? '';
        field.dataset.fromPrice = price === undefined ? '' : '1';
    }
}
