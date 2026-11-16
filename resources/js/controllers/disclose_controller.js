import { Controller } from '@hotwired/stimulus';
import { focusField } from '../keyboard';

// Шторка «Показать менеджеру» (`x-deal.reply-review`, 03.11.2026): галки пунктов, карандаш — значение полем (правка сама
// ставит галку), «Показать менеджеру (N)»; ничего не отмечено — только шаг («Пройти шаг») или кнопка неживая.
// С 16.11.2026 пункты — карточками писем (`group`): кружок в шапке карточки — отмечено всё / часть / ничего, нажатие — всё письмо
// (шапку не сворачивает); «Весь текст» раскрывает текст письма целиком.
export default class extends Controller {
    static targets = ['row', 'text', 'input', 'note', 'step', 'submit', 'group'];

    connect() {
        this.update();
        // «Весь текст» — только когда текст правда обрезан; меряем, когда карточка видна (шторка и карточка открыты).
        this.fits = new ResizeObserver((entries) => entries.forEach(({ target }) => {
            const more = target.closest('[data-disclose-target="row"]')?.nextElementSibling;
            if (more?.classList.contains('disclose-more') && !target.classList.contains('is-open')) more.hidden = target.scrollHeight <= target.clientHeight + 1;
        }));
        this.element.querySelectorAll('.disclose-text').forEach((t) => this.fits.observe(t));
    }

    disconnect() {
        this.fits?.disconnect();
    }

    edit(event) {
        const row = event.currentTarget.closest('[data-disclose-target="row"]');
        const input = row?.querySelector('[data-disclose-target="input"]');
        const text = row?.querySelector('[data-disclose-target="text"]');
        if (!input) return;
        input.hidden = false;
        if (text) text.hidden = true;
        focusField(input);
    }

    typed(event) {
        const row = event.target.closest('[data-disclose-target="row"]');
        const box = row?.querySelector('input[type=checkbox]');
        const text = row?.querySelector('[data-disclose-target="text"]');
        if (text) text.textContent = event.target.value;
        if (box) box.checked = event.target.value.trim() !== '';
        this.update();
    }

    all(event) {
        event.preventDefault();
        event.stopPropagation();
        const group = event.currentTarget.closest('[data-disclose-target="group"]');
        const boxes = [...group.querySelectorAll('input[type=checkbox][name="on[]"]')];
        const on = !boxes.every((b) => b.checked);
        boxes.forEach((b) => { b.checked = on; });
        this.update();
    }

    more(event) {
        const button = event.currentTarget;
        const text = button.previousElementSibling?.querySelector('.disclose-text');
        if (!text) return;
        const open = text.classList.toggle('is-open');
        button.textContent = open ? 'Свернуть' : 'Весь текст';
    }

    update() {
        this.groupTargets.forEach((group) => {
            const boxes = [...group.querySelectorAll('input[type=checkbox][name="on[]"]')];
            const n = boxes.filter((b) => b.checked).length;
            group.dataset.picked = !n ? 'none' : (n === boxes.length ? 'all' : 'part');
        });
        const picked = this.rowTargets.filter((row) => row.querySelector('input[type=checkbox]')?.checked).length
            + (this.hasNoteTarget && this.noteTarget.value.trim() !== '' ? 1 : 0);
        const step = this.hasStepTarget && this.stepTarget.checked;
        if (!this.hasSubmitTarget) return;
        this.submitTarget.disabled = !picked && !step;
        this.submitTarget.textContent = picked ? `Показать менеджеру (${picked})` : (step ? 'Пройти шаг' : 'Показать менеджеру');
    }
}
