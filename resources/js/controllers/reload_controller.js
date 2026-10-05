import { Controller } from '@hotwired/stimulus';

// Фрейм, который перечитывается по живому событию (карточка чата на странице сделки — по `live:chat`): адрес с меткой
// времени, иначе тот же src Turbo не перезагрузит.
export default class extends Controller {
    static values = { url: String };

    load() {
        this.element.src = this.urlValue + (this.urlValue.includes('?') ? '&' : '?') + 't=' + Date.now();
    }
}
