import { Controller } from '@hotwired/stimulus';

// Выбор строк «Наличия» галочками: кнопка плашки говорит, сколько выбрано («В продажу 3 ТС»), и молчит, пока не выбрано
// ничего. Галочки лежат в строках таблицы (form="sale-form") — живой поиск подменяет список целиком, поэтому
// считаем по документу, а не по целям внутри контроллера.
export default class extends Controller {
    static targets = ['label'];
    static values = { verb: String };

    connect() {
        this.onChange = () => this.update();
        document.addEventListener('change', this.onChange);
        document.addEventListener('turbo:render', this.onChange);
        document.addEventListener('turbo:frame-render', this.onChange);
        // Живой поиск подменяет #cars без событий Turbo.
        this.observer = new MutationObserver(this.onChange);
        const list = document.getElementById('cars');
        if (list) this.observer.observe(list, { childList: true, subtree: true });
        this.update();
    }

    disconnect() {
        document.removeEventListener('change', this.onChange);
        document.removeEventListener('turbo:render', this.onChange);
        document.removeEventListener('turbo:frame-render', this.onChange);
        this.observer?.disconnect();
    }

    update() {
        const n = [...document.querySelectorAll('input[form="sale-form"]:checked')].filter((b) => !b.disabled).length;
        // «ТС» не склоняется.
        this.labelTarget.textContent = n ? `${this.verbValue} ${n} ТС` : this.verbValue;
        this.labelTarget.closest('button')?.toggleAttribute('disabled', n === 0);
    }
}
