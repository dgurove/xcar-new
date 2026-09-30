import { Controller } from '@hotwired/stimulus';

// Кружок аватара с камерой: нажатие открывает выбор файла, выбранное фото сразу
// встаёт в кружок. Сам <input type=file> спрятан (sr-only). upload — ещё и сохраняет:
// контроллер стоит на самой форме (профиль — без кнопки «Сохранить»).
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

    upload() {
        this.preview();
        if (this.inputTarget.files?.length) this.element.requestSubmit();
    }
}
