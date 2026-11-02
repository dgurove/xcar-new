import { Controller } from '@hotwired/stimulus';

// Выбор нескольких: счётчик на кнопке, кнопка выключена без выбора, фильтр чипов по имени.
export default class extends Controller {
    static targets = ['box', 'count', 'submit', 'item'];

    connect() { this.count(); }

    // Вопрос к отмеченному (`data-select-ask` с его value, «Показать | Скрыть» заявленную на оценке): виден и уходит с
    // формой только у отмеченного, кнопка живая, когда ответили за всех.
    count() {
        const on = this.boxTargets.filter((b) => b.checked && !b.disabled);
        let unanswered = 0;
        this.boxTargets.forEach((b) => {
            const ask = this.element.querySelector(`[data-select-ask="${b.value}"]`);
            if (!ask) return;
            const shown = b.checked && !b.disabled;
            ask.hidden = !shown;
            ask.querySelectorAll('input').forEach((i) => { i.disabled = !shown; });
            if (shown && !ask.querySelector('input:checked')) unanswered++;
        });
        const n = on.length;
        // Строка только для одного (эксклюзив после оценки): видна и уходит с формой, когда отмечен ровно один.
        this.element.querySelectorAll('[data-select-single]').forEach((el) => {
            el.hidden = n !== 1;
            el.querySelectorAll('input').forEach((i) => { i.disabled = n !== 1; });
        });
        if (this.hasCountTarget) this.countTarget.textContent = n ? `(${n})` : '';
        if (this.hasSubmitTarget) this.submitTarget.disabled = n === 0 || unanswered > 0;
    }

    filter(event) {
        const q = event.target.value.trim().toLowerCase();
        this.itemTargets.forEach((el) => { el.hidden = q !== '' && !el.dataset.name.includes(q); });
    }
}
