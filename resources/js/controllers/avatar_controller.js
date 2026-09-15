import { Controller } from '@hotwired/stimulus';

// Кружок аватара с камерой: нажатие открывает выбор файла, выбранное фото сразу
// встаёт в кружок. Сам <input type=file> спрятан (sr-only).
export default class extends Controller {
    static targets = ['circle', 'input'];

    pick() {
        this.inputTarget.click();
    }

    preview() {
        const file = this.inputTarget.files?.[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        this.circleTarget.innerHTML = `<img src="${url}" alt="">`;
    }
}
