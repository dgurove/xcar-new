import { Controller } from '@hotwired/stimulus';

// «Сохранить изменения» у формы правки: тронул поле — выезжает кнопка (x-ui.save-bar с data-save-bar="<id формы>"
// или цель bar внутри формы), нажал — обычная отправка формы, экран остаётся тем же. Поля считаются и вне формы, если
// они к ней привязаны атрибутом form= (деньги и метки редактора предложения). partial — карточка строки: уходят только
// тронутые поля (имена в `_fields[]`, OfferRequest::payload), чужие правки остальных не перетираются. dirty — форма
// вернулась с ошибками проверки: кнопка видна сразу, введённое — из old(); sent — прежний `_fields[]` карточки.
// Кнопка с formaction (x-ui.save-bar :action) шлёт ту же форму на другой адрес — сохранить часть формы.
// Одно сохранение на всё (владелец 05.10.2026: «Сохранить» стирало цену в «Оценить», «Оценить» — несохранённые поля):
// - «Оценить» и «Закупочная» сверху карточки — поля и кнопки этой же формы (form=, кнопка с then=advance, 08.10.2026);
//   их поля помечены data-save-own: в тихое сохранение перед чужой формой не идут (набрал цену, передумал и отдал «В
//   гараж» — машина не должна уехать оценённой), Enter в них — своя кнопка, Enter в остальных полях карточки —
//   «Сохранить изменения», а не первая кнопка формы («Оценить»);
// - любая другая форма рядом («+15 мин», «Принять», «В гараж», состояние) при несохранённом сначала тихо
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
        // Не ушло (нет связи, сбой сервера) — кнопка снова живая с прежней подписью: иначе висела «Сохраняем…»
        // выключенной навсегда, и повторить было нечем (07.10.2026). Тост «Нет связи» с «Повторить» — net.js.
        this.onEnd = (e) => { if (e.target === this.element && !e.detail.success) this.unlock(); };
        this.element.addEventListener('turbo:submit-end', this.onEnd);
        this.onKey = (e) => this.enter(e);
        document.addEventListener('keydown', this.onKey);
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
        this.element.removeEventListener('turbo:submit-end', this.onEnd);
        document.removeEventListener('keydown', this.onKey);
    }

    // Enter в поле карточки (partial): браузер отправил бы форму первой её кнопкой — у черновика без цены это «Оценить»
    // сверху, и правка пробега уводила бы к следующей строке. Здесь Enter — «Сохранить изменения»; ничего не тронуто —
    // ничего. Поля «Оценить» (data-save-own) — как браузер: их кнопкой.
    enter(e) {
        const el = e.target;
        if (!this.partialValue || e.key !== 'Enter' || e.defaultPrevented || e.isComposing || el.tagName !== 'INPUT' || el.form !== this.element || 'saveOwn' in el.dataset) return;
        e.preventDefault();
        const button = this.bars.map((b) => b.querySelector('[data-save-bar-button]')).find(Boolean);
        if (this.touched && button) this.element.requestSubmit(button);
    }

    // scope — считаются только поля внутри этого куска формы (поля ТС в форме шага дела: правка полей шага кнопку не зовёт).
    owns(el) {
        if (!el || !(el.form === this.element || this.element.contains(el))) return false;
        return !this.scopeValue || !!el.closest(this.scopeValue);
    }

    touch(el) {
        if (el.closest('[data-controller~="audience"]')) {
            // В шторке «Кому» свои списки без имён — сохраняется только итог в скрытом поле.
            if (el.name !== 'audience_rules') return;
            this.dirty.add('audience_rules');
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
        if (this.partialValue) {
            this.element.querySelectorAll('input[name="_fields[]"]').forEach((i) => i.remove());
            // Ничего не тронуто («Оценить» с пустым полем) — список из одной метки `_none`: сервер ничего не пишет и ведёт дальше (пустую строку Laravel делает null).
            for (const name of this.dirty.size ? this.dirty : ['_none']) {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = '_fields[]';
                i.value = name;
                this.element.append(i);
            }
        }
        // Кнопку гасим после того, как Turbo собрал форму (выключенная кнопка в данные не попала бы).
        if (button?.matches('[data-save-bar-button]')) {
            button.dataset.label ??= button.textContent;
            setTimeout(() => { button.disabled = true; button.textContent = 'Сохраняем…'; });
        }
    }

    unlock() {
        this.bars.forEach((bar) => bar.querySelectorAll('[data-save-bar-button]').forEach((b) => {
            b.disabled = false;
            if (b.dataset.label) b.textContent = b.dataset.label;
        }));
    }

    // Чужая форма рядом (та же карточка или страница) при несохранённом: сначала сохранить эту, потом отправить ту.
    before(event) {
        const form = event.target;
        if (form === this.element || !this.touched || this.saving || (form.method || '').toLowerCase() === 'get') return;
        // Форма, которой несохранённое не нужно (`data-save-skip`: «Это она» у двойника — черновика после неё не будет).
        if ('saveSkip' in form.dataset) return;
        const frame = this.element.closest('turbo-frame');
        if (frame ? !frame.contains(form) : form.closest('turbo-frame#detail')) return;
        // Поля «Оценить» несёт только своя кнопка: тронуты только они — чужая форма уходит как есть.
        const own = new Set([...this.element.elements].filter((el) => 'saveOwn' in el.dataset).map((el) => el.name));
        const fields = [...this.dirty].filter((name) => !own.has(name));
        if (this.partialValue && !fields.length) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        this.saveThen(form, event.submitter, fields);
    }

    async saveThen(form, submitter, fields) {
        const done = await this.quiet(fields);
        if (done === 'offline') {
            window.toast?.('Нет связи', { kind: 'danger', action: { label: 'Повторить', run: () => this.saveThen(form, submitter, fields) } });
            return;
        }
        if (done === 'ok') form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
    }

    // Тихо сохранить тронутое (fetch с теми же полями): 'ok'; не прошло проверку — обычная отправка этой формы, ошибки
    // встанут у полей ('invalid'); нет связи — 'offline', ничего не ушло. Им же «Сохранить» в «Сохранить изменения?»
    // карточки строки (detail_controller.leave).
    async quiet(fields = [...this.dirty]) {
        this.saving = true;
        const data = new FormData(this.element);
        if (this.partialValue) for (const name of fields.length ? fields : ['_none']) data.append('_fields[]', name);
        try {
            // Успех — редирект (за ним не идём), ошибка проверки — 422 в JSON.
            const res = await fetch(this.element.action, { method: 'POST', body: data, redirect: 'manual', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
            if (res.type !== 'opaqueredirect' && !res.ok) {
                this.saving = false;
                this.element.requestSubmit();
                return 'invalid';
            }
        } catch {
            this.saving = false;
            return 'offline';
        }
        this.touched = false;
        this.dirty.clear();
        delete this.element.dataset.dirty;
        this.bars.forEach((b) => { b.hidden = true; });
        this.saving = false;
        return 'ok';
    }
}
