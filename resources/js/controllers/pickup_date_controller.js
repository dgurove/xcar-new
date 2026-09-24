import { Controller } from '@hotwired/stimulus';

// Дата в анкете покупателя: две недели — радиокнопками, «Позже» — системный календарь поверх карточки. Выбранный
// в нём день встаёт значением последней радиокнопки, карточка показывает его вместо «позже».
export default class extends Controller {
    static targets = ['later', 'laterLabel'];

    pick(event) {
        const value = event.target.value;
        if (!value) return;
        const day = new Date(value + 'T00:00');
        this.laterTarget.value = value;
        this.laterTarget.checked = true;
        const fmt = (o) => day.toLocaleDateString('ru-RU', o).replace('.', '');
        this.laterLabelTarget.innerHTML = '';
        for (const [tag, text, cls] of [['small', fmt({ weekday: 'short' })], ['b', String(day.getDate()), 'nums'], ['small', fmt({ month: 'short' })]]) {
            const el = document.createElement(tag);
            el.textContent = text;
            if (cls) el.className = cls;
            this.laterLabelTarget.append(el);
        }
    }
}
