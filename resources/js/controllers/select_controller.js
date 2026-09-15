import { Controller } from '@hotwired/stimulus';

// Выбор нескольких: счётчик на кнопке, кнопка выключена без выбора, фильтр чипов по имени.
export default class extends Controller {
    static targets = ['box', 'count', 'submit', 'item'];

    connect() { this.count(); }

    count() {
        const n = this.boxTargets.filter((b) => b.checked && !b.disabled).length;
        if (this.hasCountTarget) this.countTarget.textContent = n ? `(${n})` : '';
        if (this.hasSubmitTarget) this.submitTarget.disabled = n === 0;
    }

    filter(event) {
        const q = event.target.value.trim().toLowerCase();
        this.itemTargets.forEach((el) => { el.hidden = q !== '' && !el.dataset.name.includes(q); });
    }
}
