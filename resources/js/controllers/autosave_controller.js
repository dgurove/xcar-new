import { Controller } from '@hotwired/stimulus';

// Форма без кнопки «Сохранить»: изменил поле и ушёл из него — вся форма уходит fetch-ем, окошко не
// перерисовывается (фокус и прокрутка на месте). По одному сохранению за раз: пришла правка, пока
// шло прошлое, — отправится следом. Ответ: свежая строка таблицы и полоса окошка (событие peek:refresh),
// ошибки — подсветкой поля и тостом.
export default class extends Controller {
    connect() {
        this.onChange = (e) => { if (e.target.name || e.target.closest('[data-managers-target]')) this.save(); };
        this.onSubmit = (e) => { e.preventDefault(); this.save(); };
        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('submit', this.onSubmit);
    }

    async save() {
        if (this.busy) { this.again = true; return; }
        this.busy = true;
        try {
            const r = await fetch(this.element.action, {
                method: 'POST',
                body: new FormData(this.element),
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await r.json().catch(() => ({}));
            this.clear();
            if (r.status === 422 && data.errors) {
                this.mark(data.errors);
                window.toast?.(this.plain(Object.values(data.errors)[0]?.[0]) || 'Не сохранилось', 'danger');
            } else if (!r.ok) {
                window.toast?.('Не сохранилось', 'danger');
            } else {
                window.dispatchEvent(new CustomEvent('peek:refresh', { detail: data }));
                window.toast?.('Сохранено');
            }
        } catch {
            window.toast?.('Нет связи, не сохранилось', 'danger');
        } finally {
            this.busy = false;
            if (this.again) { this.again = false; this.save(); }
        }
    }

    // Ошибка — у поля, как после обычной отправки формы: рамка и текст под ним.
    mark(errors) {
        for (const [name, messages] of Object.entries(errors)) {
            const field = this.element.querySelector(`[name="${CSS.escape(name)}"]`)?.closest('.field');
            if (!field) continue;
            field.classList.add('field-invalid');
            const p = document.createElement('p');
            p.className = 'field-error';
            p.dataset.autosaveError = '';
            p.textContent = this.plain(messages[0]);
            field.append(p);
        }
    }

    // Фразы интерфейса без точки в конце — и сообщения валидации тоже.
    plain(text) {
        return (text ?? '').replace(/\.\s*$/, '');
    }

    clear() {
        this.element.querySelectorAll('[data-autosave-error]').forEach((p) => p.remove());
        this.element.querySelectorAll('.field-invalid').forEach((f) => f.classList.remove('field-invalid'));
    }
}
