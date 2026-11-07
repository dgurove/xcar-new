import { Controller } from '@hotwired/stimulus';

// Значения источников поля правки выпадающим списком (x-car.source-values, 08.11.2026): фокус в поле — список поверх формы
// сразу под полем ввода (не под меткой спора), уход фокуса, Esc, набор своего и выбор — закрывают (уход — через 150 мс:
// нажатие по строке уводит фокус раньше щелчка, как у справочника). Выбор — значение в поле и события ввода: разряды суммы
// и «Сохранить изменения» срабатывают сами, пишет обычное сохранение формы.
export default class extends Controller {
    connect() {
        this.field = this.element.closest('.field');
        this.input = this.field?.querySelector('input:not([type=hidden]), textarea');
        if (!this.input) return;
        this.onIn = (e) => { if (e.target === this.input) this.open(); };
        this.onOut = () => {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { if (!this.field.contains(document.activeElement)) this.close(); }, 150);
        };
        this.onType = (e) => { if (e.isTrusted) this.close(); };
        this.onKey = (e) => { if (e.key === 'Escape') this.close(); };
        this.field.addEventListener('focusin', this.onIn);
        this.field.addEventListener('focusout', this.onOut);
        this.input.addEventListener('input', this.onType);
        this.input.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.field?.removeEventListener('focusin', this.onIn);
        this.field?.removeEventListener('focusout', this.onOut);
        this.input?.removeEventListener('input', this.onType);
        this.input?.removeEventListener('keydown', this.onKey);
    }

    open() {
        clearTimeout(this.timer);
        // Сразу под полем ввода: между ним и списком может стоять метка спора «или 2024 ›».
        const box = this.input.closest('.vin-box') ?? this.input;
        this.element.style.top = `${box.offsetTop + box.offsetHeight}px`;
        this.element.style.left = '';
        this.element.hidden = false;
        // Список шире узкого поля (телефон, поле в полстроки): не влез справа — сдвигается влево, к краю экрана.
        const over = this.element.getBoundingClientRect().right - (document.documentElement.clientWidth - 8);
        if (over > 0) this.element.style.left = `${-over}px`;
    }

    close() {
        this.element.hidden = true;
    }

    pick(event) {
        this.input.value = String(event.params.value ?? '');
        this.input.dispatchEvent(new Event('input', { bubbles: true }));
        this.input.dispatchEvent(new Event('change', { bubbles: true }));
        this.close();
    }
}
