import { Controller } from '@hotwired/stimulus';

// Дата в анкете покупателя: «Завтра» и «Послезавтра» ставят день сами, «Другой день» открывает системный календарь.
export default class extends Controller {
    static targets = ['date'];

    pick(event) {
        this.dateTarget.value = event.target.value;
        this.dateTarget.classList.add('hidden');
    }

    other() {
        this.dateTarget.classList.remove('hidden');
        this.dateTarget.focus();
        try { this.dateTarget.showPicker?.(); } catch {}
    }
}
