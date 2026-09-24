import { Controller } from '@hotwired/stimulus';

// Дата в анкете покупателя: две недели — радиокнопками, «Позже» — системный календарь поверх карточки. Выбранный
// в нём день встаёт значением последней радиокнопки, карточка показывает его вместо «позже».
export default class extends Controller {
    static targets = ['later', 'laterLabel'];
    static values = { weekdays: Array, months: Array };

    pick(event) {
        const value = event.target.value;
        if (!value) return;
        const day = new Date(value + 'T00:00');
        this.laterTarget.value = value;
        this.laterTarget.checked = true;
        this.laterLabelTarget.innerHTML = '';
        for (const [tag, text, cls] of [['small', this.weekdaysValue[(day.getDay() + 6) % 7]], ['b', String(day.getDate()), 'nums'], ['small', this.monthsValue[day.getMonth()]]]) {
            const el = document.createElement(tag);
            el.textContent = text;
            if (cls) el.className = cls;
            this.laterLabelTarget.append(el);
        }
    }
}
