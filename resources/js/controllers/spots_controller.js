import { Controller } from '@hotwired/stimulus';

// Свободные места выбранной площадки — подсказкой в поле «Место».
export default class extends Controller {
    static targets = ['yard', 'list'];
    static values = { map: Object };

    connect() { this.sync(); }

    sync() {
        const spots = this.mapValue[this.yardTarget.value] || [];
        this.listTarget.replaceChildren(...spots.map((s) => Object.assign(document.createElement('option'), { value: s })));
    }
}
