import { Controller } from '@hotwired/stimulus';
import { focusField } from '../keyboard';

// Шторка «Показать менеджеру» (`x-deal.reply-review`, 03.11.2026): галки пунктов, карандаш — значение полем (правка сама
// ставит галку), «Показать менеджеру (N)»; ничего не отмечено — только шаг («Пройти шаг») или кнопка неживая.
export default class extends Controller {
    static targets = ['row', 'text', 'input', 'note', 'step', 'submit'];

    connect() {
        this.update();
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

    update() {
        const picked = this.rowTargets.filter((row) => row.querySelector('input[type=checkbox]')?.checked).length
            + (this.hasNoteTarget && this.noteTarget.value.trim() !== '' ? 1 : 0);
        const step = this.hasStepTarget && this.stepTarget.checked;
        if (!this.hasSubmitTarget) return;
        this.submitTarget.disabled = !picked && !step;
        this.submitTarget.textContent = picked ? `Показать менеджеру (${picked})` : (step ? 'Пройти шаг' : 'Показать менеджеру');
    }
}
