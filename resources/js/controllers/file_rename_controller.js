import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';

// Переименовать документ в строке (`x-ui.file-rename`, 13.11.2026): имя становится полем на месте, Enter или «✓» —
// PATCH с `name` (JSON-ответ — новое имя, `Media\DocName`), Esc или уход из поля без правки — как было. Своей формы нет:
// строка бывает внутри формы отчёта о приёме.
export default class extends Controller {
    static targets = ['edit', 'save'];
    static values = { url: String, name: String };

    get row() {
        return this.element.closest('.file-row');
    }

    get label() {
        return this.row?.querySelector('[data-file-name]');
    }

    start(e) {
        e.preventDefault();
        const label = this.label;
        if (!label || this.input) return;
        const dot = this.nameValue.lastIndexOf('.');
        const ext = dot > 0 && this.nameValue.length - dot <= 6 ? this.nameValue.slice(dot) : '';
        this.ext = ext;
        this.input = Object.assign(document.createElement('input'), {
            type: 'text', className: 'field-input field-s file-rename-input', value: ext ? this.nameValue.slice(0, -ext.length) : this.nameValue,
            maxLength: 120, autocomplete: 'off', enterKeyHint: 'done',
        });
        this.input.setAttribute('aria-label', 'Новое имя');
        this.input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') { ev.preventDefault(); this.save(); }
            if (ev.key === 'Escape') { ev.preventDefault(); this.cancel(); }
        });
        // Нажатие по полю не открывает документ (имя лежит в ссылке строки).
        this.input.addEventListener('click', (ev) => { ev.preventDefault(); ev.stopPropagation(); });
        label.hidden = true;
        label.after(this.input);
        this.editTarget.hidden = true;
        this.saveTarget.hidden = false;
        this.input.focus();
        this.input.select();
    }

    cancel() {
        this.input?.remove();
        this.input = null;
        if (this.label) this.label.hidden = false;
        this.editTarget.hidden = false;
        this.saveTarget.hidden = true;
    }

    async save(e) {
        e?.preventDefault();
        if (!this.input || this.busy) return;
        const name = this.input.value.trim();
        if (name + this.ext === this.nameValue) { this.cancel(); return; }
        this.busy = true;
        this.saveTarget.disabled = true;
        try {
            const r = await request(this.urlValue, { method: 'PATCH', json: { name }, statuses: [422, 403] });
            const answer = r ? await r.json().catch(() => ({})) : null;
            if (!r?.ok || !answer?.name) throw new Error(answer?.message || 'Не переименовалось');
            this.nameValue = answer.name;
            if (this.label) this.label.textContent = answer.name;
            // Шторка документов берёт имя из ссылки строки.
            const link = this.row?.querySelector('a[data-doc-name]');
            if (link) { link.dataset.docName = answer.name; link.title = answer.name; }
            this.cancel();
        } catch (err) {
            this.input.setCustomValidity(err.message);
            this.input.reportValidity?.();
            this.input.classList.add('is-shaking');
            setTimeout(() => this.input?.classList.remove('is-shaking'), 500);
        } finally {
            this.busy = false;
            this.saveTarget.disabled = false;
        }
    }
}
