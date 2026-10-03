import { Controller } from '@hotwired/stimulus';

// Форма без кнопки «Сохранить»: изменил поле и ушёл из него — уходят только тронутые поля (их имена в
// `_fields[]`), окошко не перерисовывается. Чужие правки остальных полей не перетираются. Середину правки
// не сохраняем: марка без модели (модель после смены марки сбрасывается) ждёт следующего изменения. Пачку
// изменений подряд (разбор VIN заполняет поля по очереди) отправляет одним запросом, по одному сохранению за раз. Ответ — редирект на строку: свежая строка
// таблицы и полоса карточки (потоки Turbo); ошибки — у поля и тостом.
export default class extends Controller {
    connect() {
        this.dirty = new Set();
        this.onChange = (e) => this.touch(e.target);
        this.onSubmit = (e) => { e.preventDefault(); this.later(0); };
        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('submit', this.onSubmit);
    }

    touch(el) {
        if (el.closest('[data-controller~="audience"]')) {
            // В шторке «Кому» свои списки без имён — сохраняет только итог в скрытом поле.
            if (el.name !== 'audience_rules') return;
            this.dirty.add('audience_rules');
        } else if (el.name && !el.name.startsWith('_')) {
            this.dirty.add(el.name.replace(/\[\]$/, ''));
        } else return;
        this.later(250);
    }

    later(ms) {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.save(), ms);
    }

    // Середина правки: сохранять рано.
    unfinished(form) {
        return this.dirty.has('brand_id') && form.get('brand_id') && !form.get('model_id');
    }

    async save() {
        if (!this.dirty.size) return;
        if (this.busy) { this.again = true; return; }
        const form = new FormData(this.element);
        if (this.unfinished(form)) return;
        const sent = [...this.dirty];
        this.dirty.clear();
        const body = new FormData();
        body.append('_token', form.get('_token'));
        body.append('_method', 'put');
        for (const name of sent) {
            body.append('_fields[]', name);
            for (const key of [name, `${name}[]`]) form.getAll(key).forEach((v) => body.append(key, v));
        }
        this.busy = true;
        try {
            const r = await fetch(this.element.action, { method: 'POST', body, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await r.json().catch(() => ({}));
            this.clear();
            if (r.status === 422 && data.errors) {
                this.mark(data.errors);
                window.toast?.(this.plain(Object.values(data.errors)[0]?.[0]) || 'Не сохранилось', 'danger');
            } else if (!r.ok) {
                sent.forEach((n) => this.dirty.add(n));
                window.toast?.('Не сохранилось', 'danger');
            } else {
                if (data.streams) window.Turbo.renderStreamMessage(data.streams);
                window.toast?.('Сохранено');
            }
        } catch {
            sent.forEach((n) => this.dirty.add(n));
            window.toast?.('Нет связи, не сохранилось', 'danger');
        } finally {
            this.busy = false;
            if (this.again) { this.again = false; this.save(); }
        }
    }

    // Ошибка — у поля, как после обычной отправки формы: рамка и текст под ним.
    mark(errors) {
        for (const [name, messages] of Object.entries(errors)) {
            const field = this.element.querySelector(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name.replace(/\.\d+$/, ''))}[]"]`)?.closest('.field');
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
