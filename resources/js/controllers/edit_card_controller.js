import { Controller } from '@hotwired/stimulus';

// «Изменить / Готово», как в Контактах iOS (владелец 06.10.2026): у опубликованной машины карточки «Транспортное
// средство» и «Цены» показывают данные текстом в той же сетке, что поля; «Изменить» — поля на месте, «Готово» — снова
// текст. Правили — «Готово» сохраняет форму (обычная отправка, страница вернётся с текстом); не правили — просто
// сворачивает. Поля всё время в форме (скрыты, не выключены): сохранение соседних полей шлёт их как есть.
// Переход — высота карточки тянется к новой (FLIP), новый слой проявляется с лёгким сдвигом, поля — лесенкой.
export default class extends Controller {
    static targets = ['view', 'edit', 'button'];
    static values = { editing: Boolean, form: String };

    connect() {
        this.apply(this.editingValue, false);
        // Открыта сама (ошибки, расхождения): «Готово» сохраняет то, что в полях, а не прячет его.
        if (this.editingValue) this.snapshot = '';
    }

    toggle() {
        if (this.editingValue && this.changed()) {
            this.buttonTarget.disabled = true;
            this.form()?.requestSubmit();
            return;
        }
        this.editingValue = !this.editingValue;
        this.apply(this.editingValue, true);
        if (this.editingValue) {
            this.snapshot = this.serialize();
            this.editTarget.querySelector('input:not([type=hidden]):not([disabled]), select, textarea, [role=combobox]')?.focus({ preventScroll: true });
        }
    }

    apply(editing, animate) {
        const card = this.element;
        const motion = animate && !matchMedia('(prefers-reduced-motion: reduce)').matches && card.animate;
        const from = motion ? card.getBoundingClientRect().height : 0;
        this.viewTarget.hidden = editing;
        this.editTarget.hidden = !editing;
        card.toggleAttribute('data-editing', editing);
        // Пусто — кнопка зовёт добавить («Добавить», data-idle), а не «Изменить».
        for (const b of this.buttonTargets) b.textContent = editing ? 'Готово' : (b.dataset.idle || 'Изменить');
        if (!motion) return;

        const to = card.getBoundingClientRect().height;
        const ease = 'cubic-bezier(.2, .8, .2, 1)';
        card.style.overflow = 'clip';
        card.animate([{ height: `${from}px` }, { height: `${to}px` }], { duration: 320, easing: ease })
            .finished.finally(() => { card.style.overflow = ''; });
        const shown = editing ? this.editTarget : this.viewTarget;
        shown.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }], { duration: 260, easing: ease });
        // Ячейки сетки — лесенкой, сверху вниз: глаз видит, что текст стал полями там же, где стоял.
        [...(shown.querySelector(':scope > .grid')?.children ?? [])].slice(0, 24).forEach((cell, i) => {
            cell.animate([{ opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' }],
                { duration: 220, delay: 20 * i, easing: ease, fill: 'backwards' });
        });
    }

    form() {
        return this.formValue ? document.getElementById(this.formValue) : this.element.closest('form');
    }

    // Что в полях карточки — для «правили или нет» (поля вне формы привязаны к ней атрибутом form=).
    serialize() {
        return [...this.editTarget.querySelectorAll('input[name], select[name], textarea[name]')]
            .map((el) => `${el.name}=${el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value}`).join('&');
    }

    changed() {
        return this.snapshot !== undefined && this.serialize() !== this.snapshot;
    }
}
