import { Controller } from '@hotwired/stimulus';

// День и время подписания в два шага (`cabinet.deals.signing-when-form`, 11.11.2026): дни → «Дальше» → время, «‹» — назад к
// дням. Одна форма: шаги только прячутся, отмеченное не теряется. Кнопки живые, когда отмечено хоть что-то; «Весь день»
// отмечает всё время и гаснет, если снять одно.
export default class extends Controller {
    static targets = ['days', 'times', 'next', 'submit', 'summary', 'all'];

    connect() {
        this.update();
        // Ошибка про время с сервера или дни уже названы — сразу на время.
        if (this.timesTarget.querySelector('.field-error')) this.show('times');
    }

    update() {
        const days = this.checked('days[]');
        const times = this.checked('times[]');
        this.nextTarget.disabled = days.length === 0;
        this.submitTarget.disabled = times.length === 0;
        this.summaryTarget.textContent = days.map((i) => i.dataset.label).join(', ');
        const all = this.inputs('times[]');
        this.allTarget.checked = all.length > 0 && times.length === all.length;
    }

    next() {
        if (this.checked('days[]').length) this.show('times');
    }

    back() {
        this.show('days');
    }

    all() {
        for (const i of this.inputs('times[]')) i.checked = this.allTarget.checked;
        this.update();
    }

    show(step) {
        const [on, off] = step === 'times' ? [this.timesTarget, this.daysTarget] : [this.daysTarget, this.timesTarget];
        off.hidden = true;
        on.hidden = false;
        on.classList.remove('sw-in-next', 'sw-in-back');
        void on.offsetWidth;
        on.classList.add(step === 'times' ? 'sw-in-next' : 'sw-in-back');
    }

    inputs(name) {
        return [...this.element.querySelectorAll(`input[name="${name}"]`)];
    }

    checked(name) {
        return this.inputs(name).filter((i) => i.checked);
    }
}
