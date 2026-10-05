import { Controller } from '@hotwired/stimulus';

// «Сохранить изменения» у формы правки: тронул поле — выезжает кнопка (x-ui.save-bar с data-save-bar="<id формы>"
// или цель bar внутри формы), нажал — обычная отправка формы, экран остаётся тем же. Поля считаются и вне формы, если
// они к ней привязаны атрибутом form= (деньги и метки редактора предложения). partial — карточка строки: уходят только
// тронутые поля (имена в `_fields[]`, OfferRequest::payload), чужие правки остальных не перетираются. dirty — форма
// вернулась с ошибками проверки: кнопка видна сразу, введённое — из old(); sent — прежний `_fields[]` карточки.
// Кнопка с formaction (x-ui.save-bar :action) шлёт ту же форму на другой адрес — сохранить часть формы.
// Одно сохранение на всё (владелец 05.10.2026: «Сохранить» стирало цену в «Оценить», «Оценить» — несохранённые поля):
// - поле вне формы с data-save-into="<id формы>" и data-save-name (цена в «Оценить») считается её полем — «Сохранить»
//   уносит и его;
// - любая другая форма рядом («Оценить», «+15 мин», «Принять», «В гараж», состояние) при несохранённом сначала тихо
//   сохраняет эту (fetch с теми же полями), потом отправляется сама — ответ любой из них больше ничего не стирает.
export default class extends Controller {
    static targets = ['bar', 'button'];
    static values = { partial: Boolean, dirty: Boolean, sent: Array, scope: String };

    connect() {
        this.dirty = new Set(this.sentValue);
        this.touched = this.dirtyValue || this.dirty.size > 0;
        this.onEdit = (e) => { if (this.owns(e.target)) this.touch(e.target); };
        // Описание — редактор Trix: правка приходит от редактора, поле с именем — его скрытый input.
        this.onTrix = (e) => { const input = e.target.inputElement; if (this.owns(input)) this.touch(input); };
        this.onSubmit = (e) => { if (e.target === this.element) this.submit(e); };
        document.addEventListener('input', this.onEdit);
        document.addEventListener('change', this.onEdit);
        document.addEventListener('trix-change', this.onTrix);
        this.element.addEventListener('submit', this.onSubmit);
        // До Turbo (он слушает всплытие): чужая форма ждёт, пока сохранится эта.
        this.onOther = (e) => this.before(e);
        document.addEventListener('submit', this.onOther, true);
        // Черновик формы (draft_controller) вернул набранное до перезагрузки — это несохранённое.
        if (this.element.dataset.dirty === '1') this.touched = true;
        this.show();
    }

    disconnect() {
        document.removeEventListener('input', this.onEdit);
        document.removeEventListener('change', this.onEdit);
        document.removeEventListener('trix-change', this.onTrix);
        this.element.removeEventListener('submit', this.onSubmit);
        document.removeEventListener('submit', this.onOther, true);
    }

    // scope — считаются только поля внутри этого куска формы (поля ТС в форме шага дела: правка полей шага кнопку не зовёт).
    owns(el) {
        if (el?.dataset?.saveInto && el.dataset.saveInto === this.element.id) return true;
        if (!el || !(el.form === this.element || this.element.contains(el))) return false;
        return !this.scopeValue || !!el.closest(this.scopeValue);
    }

    touch(el) {
        if (el.closest('[data-controller~="audience"]')) {
            // В шторке «Кому» свои списки без имён — сохраняется только итог в скрытом поле.
            if (el.name !== 'audience_rules') return;
            this.dirty.add('audience_rules');
        } else if (el.dataset.saveName) {
            this.dirty.add(el.dataset.saveName);
        } else if (el.name && !el.name.startsWith('_')) {
            this.dirty.add(el.name.replace(/\[\]$/, ''));
        } else return;
        this.touched = true;
        this.show();
    }

    get bars() {
        const outside = this.element.id ? [...document.querySelectorAll(`[data-save-bar="${CSS.escape(this.element.id)}"]`)] : [];
        return [...this.barTargets, ...outside];
    }

    show() {
        if (this.touched) this.element.dataset.dirty = '1';
        this.bars.forEach((b) => { b.hidden = !this.touched; });
    }

    // «Сохранить изменения» без правок не отправляет (Enter в нетронутой форме — тоже); другие кнопки формы
    // («Опубликовать», шаг дела) работают как всегда. Карточке — имена тронутых полей.
    submit(event) {
        const button = event.submitter;
        const ours = !button || button.matches('[data-save-bar-button]');
        if (!this.touched && ours) { event.preventDefault(); return; }
        this.extras();
        if (this.partialValue) {
            this.element.querySelectorAll('input[name="_fields[]"]').forEach((i) => i.remove());
            for (const name of this.dirty) {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = '_fields[]';
                i.value = name;
                this.element.append(i);
            }
        }
        // Кнопку гасим после того, как Turbo собрал форму (выключенная кнопка в данные не попала бы).
        if (button?.matches('[data-save-bar-button]')) {
            setTimeout(() => { button.disabled = true; button.textContent = 'Сохраняем…'; });
        }
    }

    // Поля вне формы (data-save-into) — скрытыми полями в саму форму: цена из «Оценить» уходит вместе с остальным.
    extras() {
        this.element.querySelectorAll('input[data-save-extra]').forEach((i) => i.remove());
        if (!this.element.id) return;
        document.querySelectorAll(`[data-save-into="${CSS.escape(this.element.id)}"][data-save-name]`).forEach((el) => {
            const value = (el.value || '').replace(/[^\d]/g, '');
            if (!value) return;
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = el.dataset.saveName;
            i.value = value;
            i.dataset.saveExtra = '';
            this.element.append(i);
            this.dirty.add(el.dataset.saveName);
        });
    }

    // Чужая форма рядом (та же карточка или страница) при несохранённом: сначала сохранить эту, потом отправить ту.
    before(event) {
        const form = event.target;
        if (form === this.element || !this.touched || this.saving || (form.method || '').toLowerCase() === 'get') return;
        const frame = this.element.closest('turbo-frame');
        if (frame ? !frame.contains(form) : form.closest('turbo-frame#detail')) return;
        // Тронута только цена из «Оценить» — её несёт сама та форма: пусть уходит как есть. Повторная отправка изнутри
        // этого же submit браузером молча пропускается (форма ещё «отправляется») — «Оценить» тогда не делало ничего.
        const fields = this.silentFields();
        if (this.partialValue && !fields.length) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        this.saveThen(form, event.submitter, fields);
    }

    // Цена из «Оценить» в тихое сохранение не идёт: её несёт сама та форма, а другой кнопке она ни к чему.
    silentFields() {
        const extra = new Set([...document.querySelectorAll(`[data-save-into="${CSS.escape(this.element.id || '-')}"][data-save-name]`)].map((el) => el.dataset.saveName));
        return [...this.dirty].filter((name) => !extra.has(name));
    }

    async saveThen(form, submitter, fields) {
        this.saving = true;
        this.element.querySelectorAll('input[data-save-extra]').forEach((i) => i.remove());
        const data = new FormData(this.element);
        if (this.partialValue) for (const name of fields) data.append('_fields[]', name);
        try {
            // Успех — редирект (за ним не идём), ошибка проверки — 422 в JSON.
            const res = await fetch(this.element.action, { method: 'POST', body: data, redirect: 'manual', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (res.type !== 'opaqueredirect' && !res.ok) {
                // Не прошло проверку — обычная отправка этой формы: ошибки встанут у полей, та форма подождёт.
                this.saving = false;
                this.element.requestSubmit();
                return;
            }
        } catch {
            this.saving = false;
            return;
        }
        this.touched = false;
        this.dirty.clear();
        delete this.element.dataset.dirty;
        this.bars.forEach((b) => { b.hidden = true; });
        form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
        this.saving = false;
    }
}
