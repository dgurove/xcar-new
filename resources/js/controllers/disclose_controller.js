import { Controller } from '@hotwired/stimulus';
import { focusField } from '../keyboard';

// Шторка «Показать менеджеру» (`x-deal.reply-review`, окно писем; 16.11.2026 — сначала готовое сообщение): поле «Сообщение
// менеджеру» уже с текстом письма, ниже галки «Приложить» (карандаш у телефона, имени, адреса — значение полем, правка сама ставит
// галку) и «Другие письма» с «В сообщение» — дописать их текст в поле. Кнопка — «Отправить менеджеру», когда в поле что-то есть
// или отмечен пункт; только галка шага — «Пройти шаг»; ничего — неживая. `own` — «Имя» и «Телефон» шторки контакта (25.11.2026).
export default class extends Controller {
    static targets = ['row', 'text', 'input', 'note', 'step', 'submit', 'own'];

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

    append(event) {
        const button = event.currentTarget;
        if (!this.hasNoteTarget || button.disabled) return;
        const note = this.noteTarget;
        const add = button.dataset.text || '';
        note.value = note.value.trim() === '' ? add : `${note.value.replace(/\s+$/, '')}\n\n${add}`;
        note.dispatchEvent(new Event('input', { bubbles: true }));
        button.textContent = 'Добавлено';
        button.disabled = true;
        this.update();
    }

    update() {
        const picked = this.rowTargets.filter((row) => row.querySelector('input[type=checkbox]')?.checked).length
            + (this.hasNoteTarget && this.noteTarget.value.trim() !== '' ? 1 : 0)
            + this.ownTargets.filter((f) => f.value.trim() !== '').length;
        const step = this.hasStepTarget && this.stepTarget.checked;
        if (!this.hasSubmitTarget) return;
        this.submitTarget.disabled = !picked && !step;
        this.submitTarget.textContent = picked ? 'Отправить менеджеру' : (step ? 'Пройти шаг' : 'Отправить менеджеру');
    }
}
