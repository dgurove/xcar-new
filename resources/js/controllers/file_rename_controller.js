import { Controller } from '@hotwired/stimulus';
import { request } from '../lib/http';

// Переименовать документ в строке (`x-ui.file-rename`, 13.11.2026): имя становится полем на месте, Enter или «✓» —
// PATCH с `name` (JSON-ответ — новое имя, `Media\DocName`), Esc или уход из поля без правки — как было. Своей формы нет:
// строка бывает внутри формы отчёта о приёме.
// Расширение — как `x-ui.file-name`: хвост до пяти знаков хоть с одной буквой («.pdf», а не «.11» в «Счёт от 12.11»).
const EXT = /(?<=.)\.(?=[a-z0-9]{0,4}[a-z])[a-z0-9]{1,5}$/i;

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
        const ext = this.nameValue.match(EXT)?.[0] ?? '';
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
            // Расширение — приглушённо, как в разметке (`x-ui.file-name`).
            if (this.label) {
                const ext = answer.name.match(EXT)?.[0] ?? '';
                const tail = Object.assign(document.createElement('span'), { className: 'file-ext', textContent: ext });
                this.label.replaceChildren(ext ? answer.name.slice(0, -ext.length) : answer.name, ...(ext ? [tail] : []));
            }
            // Шторка документов берёт имя из ссылки строки.
            // Ссылок с документом у строки бывает две (значок и имя, `x-ui.file` с подписью под именем) — обе.
            this.row?.querySelectorAll('a[data-doc-name]').forEach((link) => { link.dataset.docName = answer.name; link.title = answer.name; });
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
