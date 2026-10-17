import { Controller } from '@hotwired/stimulus';
import { closeSheet } from '../sheet';
import { copyText } from '../lib/clipboard';
import { csrf } from '../lib/http';
import { focusField } from '../keyboard';

// Выбор человека с созданием на месте (x-people.pick, 18.10.2026). Шторка: список строк с галкой и «+ Новый …»; новый —
// вторая панель той же шторки (выезд вбок, «‹» и её название в шапке, высота шторки постоянная). «Добавить» шлёт JSON на
// /people (без формы: шторка стоит и внутри чужих форм), ошибки — под полями, успех — в панели: данные входа или ссылка с
// «Отправить» и «Скопировать», а новый уже в списке и выбран. Выбор пишет скрытое поле формы; autosubmit — сразу
// отправляет форму. only — у шторки нет своего списка (кнопка «Добавить покупателя», строка в списке с галками): новый
// встаёт отмеченным в чужой список (`append` — селектор списка, имя галки — `name`) или страница перечитывается (`reload`).
export default class extends Controller {
    static targets = ['shown', 'hidden', 'list', 'panes', 'newPane', 'form', 'error', 'submit', 'done', 'doneTitle', 'doneSub', 'doneRows',
        'shareBtn', 'copyBtn', 'finish', 'finishLabel', 'addRow'];
    static values = { kind: String, url: String, name: String, deal: Number, autosubmit: Boolean, placeholder: String, only: Boolean, append: String, reload: Boolean };

    connect() {
        if (this.hasShareBtnTarget && !navigator.share) this.shareBtnTarget.hidden = true;
        this.dialog = this.element.querySelector('dialog');
        this.onClose = () => this.reset();
        this.dialog?.addEventListener('close', this.onClose);
    }

    disconnect() {
        this.dialog?.removeEventListener('close', this.onClose);
    }

    // Строка списка выбрана: поле формы, подпись в строке-поле, событие соседям; autosubmit — форма уходит.
    choose(event) {
        const option = event.target.closest('[data-people-option]');
        this.pick(event.target.value, option?.querySelector('[data-people-name]')?.textContent.trim() ?? '', option?.dataset.address);
        closeSheet(this.dialog);
    }

    pick(id, name, address) {
        if (!this.hasHiddenTarget) return;
        this.hiddenTarget.value = id;
        this.hiddenTarget.dispatchEvent(new Event('change', { bubbles: true }));
        const label = this.hasShownTarget ? this.shownTarget.querySelector('span') : null;
        if (label) {
            label.textContent = id ? name : this.placeholderValue;
            label.className = id ? 'min-w-0 truncate' : 'text-accent-text';
        }
        this.element.dispatchEvent(new CustomEvent('people:picked', { bubbles: true, detail: { id, name, address, kind: this.kindValue } }));
        if (this.autosubmitValue) this.hiddenTarget.form?.requestSubmit();
    }

    filter(event) {
        const q = event.target.value.trim().toLowerCase();
        for (const row of this.listTarget.querySelectorAll('[data-people-option]')) row.hidden = q !== '' && !row.dataset.searchText.includes(q);
    }

    // Шапка шторки — как экран iOS: на второй панели «‹» и её название; в режиме only «‹» нет — назад некуда.
    bar(isNew) {
        const title = this.dialog?.querySelector('.sheet-bar h2');
        if (!title) return;
        this.title ??= title.textContent;
        title.textContent = isNew ? this.dialog.querySelector('.people-new-pane')?.dataset.newTitle ?? this.title : this.title;
        if (this.onlyValue) return;
        if (!this.backBtn) {
            this.backBtn = Object.assign(document.createElement('button'), { type: 'button', className: 'sheet-close -ml-2' });
            this.backBtn.setAttribute('aria-label', 'Назад');
            this.backBtn.innerHTML = '<svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>';
            this.backBtn.addEventListener('click', () => this.back());
            title.before(this.backBtn);
            title.classList.add('flex-1');
        }
        this.backBtn.hidden = !isNew;
    }

    add() {
        this.bar(true);
        if (!this.onlyValue) this.panesTarget.classList.add('is-new');
        const first = this.newPaneTarget.querySelector('[data-people-field]');
        if (first) setTimeout(() => focusField(first), 300);
    }

    back() {
        this.bar(false);
        this.panesTarget.classList.remove('is-new');
    }

    mode(event) {
        this.newPaneTarget.dataset.mode = event.target.value;
        this.clearErrors();
    }

    // Шторку закрыли — в следующий раз снова список и пустая форма.
    reset() {
        if (!this.hasPanesTarget) return;
        this.back();
        if (!this.hasFormTarget) return;
        this.formTarget.hidden = false;
        this.doneTarget.hidden = true;
        this.formTarget.querySelectorAll('[data-people-field]').forEach((el) => { el.value = ''; });
        this.clearErrors();
        this.made = null;
    }

    clearErrors() {
        this.newPaneTarget.querySelectorAll('.field-invalid').forEach((f) => f.classList.remove('field-invalid'));
        this.newPaneTarget.querySelectorAll('.field-error[data-made]').forEach((e) => e.remove());
        this.errorTarget.classList.add('hidden');
    }

    async create() {
        if (this.busy) return;
        this.clearErrors();
        const link = this.newPaneTarget.dataset.mode === 'link';
        const person = {};
        if (!link) for (const el of this.newPaneTarget.querySelectorAll('[data-people-field]')) person[el.dataset.peopleField] = el.value;
        this.busy = true;
        this.submitTarget.classList.add('is-busy');
        let r;
        try {
            r = await fetch(this.urlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ kind: this.kindValue, mode: link ? 'link' : 'person', deal: this.dealValue || null, _person: person }),
            });
        } catch {
            this.fail('Нет связи');
            return;
        } finally {
            this.busy = false;
            this.submitTarget.classList.remove('is-busy');
        }
        const data = await r.json().catch(() => null);
        if (r.status === 422 && data?.errors) { this.showErrors(data.errors); return; }
        if (!r.ok || !data) { this.fail('Не получилось'); return; }
        if (data.link) this.doneLink(data.link); else this.added(data);
    }

    fail(text) {
        this.errorTarget.textContent = text;
        this.errorTarget.classList.remove('hidden');
    }

    showErrors(errors) {
        let first = null;
        for (const [key, messages] of Object.entries(errors)) {
            const input = this.newPaneTarget.querySelector(`[data-people-field="${key}"]`);
            const field = input?.closest('.field');
            if (!field) { this.fail(messages[0]); continue; }
            field.classList.add('field-invalid');
            const p = Object.assign(document.createElement('p'), { className: 'field-error', textContent: messages[0] });
            p.dataset.made = '';
            field.append(p);
            first ??= input;
        }
        if (first) focusField(first);
    }

    // Новый человек: в свой список строкой (выбранным); данные входа — в панели, без них — сразу дальше.
    added(data) {
        this.made = data;
        if (!this.onlyValue) {
            const option = this.option(data.html, 'radio', this.listTarget.querySelector('input[type=radio]')?.name ?? `_pp_${this.nameValue}`);
            if (this.hasAddRowTarget) this.addRowTarget.before(option); else this.listTarget.append(option);
            this.listTarget.querySelectorAll('input[type=radio]').forEach((i) => { i.checked = i === option.querySelector('input'); });
        }
        if (!data.credentials) { this.finish(); window.toast?.(`${data.name} в списке`); return; }
        const c = data.credentials;
        this.shareText = `Вам открыт доступ к XCar\nТелефон: ${c.phone}\nПароль: ${c.password}\nВход: ${c.url}`;
        this.doneTitleTarget.textContent = c.name;
        this.doneSubTarget.textContent = 'Отправьте данные для входа';
        this.rows([['Телефон', c.phone], ['Пароль', c.password], ['Вход', c.url.replace(/^https?:\/\//, '')]]);
        this.finishTarget.textContent = this.hasFinishLabelTarget ? this.finishLabelTarget.textContent : 'Готово';
        this.formTarget.hidden = true;
        this.doneTarget.hidden = false;
    }

    doneLink(link) {
        this.made = null;
        this.shareText = `${link.text}\n${link.url}`;
        this.doneTitleTarget.textContent = 'Ссылка готова';
        this.doneSubTarget.textContent = this.dealValue ? 'Придёт по ней — сам встанет покупателем этой сделки' : 'Одноразовая, действует неделю';
        this.rows([[null, link.url]]);
        this.finishTarget.textContent = 'Готово';
        this.formTarget.hidden = true;
        this.doneTarget.hidden = false;
    }

    rows(pairs) {
        this.doneRowsTarget.innerHTML = '';
        for (const [k, v] of pairs) {
            const row = document.createElement('div');
            row.className = 'row';
            if (k) {
                row.innerHTML = '<span class="shrink-0 text-ink-muted"></span><span class="min-w-0 flex-1 truncate text-right"></span>';
                row.children[0].textContent = k;
                row.children[1].textContent = v;
                if (k === 'Пароль') row.children[1].classList.add('font-mono', 'select-all');
            } else {
                row.innerHTML = '<span class="min-w-0 flex-1 break-all text-sm select-all"></span>';
                row.firstElementChild.textContent = v;
            }
            this.doneRowsTarget.append(row);
        }
    }

    option(html, type, name) {
        const holder = document.createElement('div');
        holder.innerHTML = html;
        const option = holder.firstElementChild;
        const input = option.querySelector('input');
        input.type = type;
        input.name = name;
        if (type === 'checkbox') input.removeAttribute('data-action');
        return option;
    }

    async share() {
        try { await navigator.share({ text: this.shareText }); } catch (e) { if (e?.name !== 'AbortError') this.copy(); }
    }

    copy() {
        copyText(this.shareText, 'В буфере');
    }

    // «Готово» / «Выбрать»: новый — в поле формы (форма уходит, если autosubmit), в чужой список отмеченным или
    // страница перечитывается; шторка закрывается.
    finish() {
        const made = this.made;
        this.made = null;
        closeSheet(this.dialog);
        if (!made) return;
        if (!this.onlyValue) { this.pick(String(made.id), made.name, made.address); return; }
        const list = this.appendValue ? document.querySelector(this.appendValue) : null;
        if (list) {
            const option = this.option(made.html, 'checkbox', this.nameValue);
            const input = option.querySelector('input');
            // Галка — как у соседних строк списка (счётчик «Открыть N», автосохранение группы).
            const twin = list.querySelector(`input[name="${CSS.escape(this.nameValue)}"]`);
            for (const a of twin?.attributes ?? []) if (a.name.startsWith('data-')) input.setAttribute(a.name, a.value);
            const row = twin?.closest('label');
            for (const a of row?.attributes ?? []) if (a.name.startsWith('data-select')) option.setAttribute(a.name, a.value);
            if (row) option.dataset.name = made.name.toLowerCase();
            input.checked = true;
            list.prepend(option);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (this.reloadValue) window.Turbo?.visit(location.href, { action: 'replace' });
    }
}
