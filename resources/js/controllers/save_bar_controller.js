import { Controller } from '@hotwired/stimulus';

// «Сохранить изменения» у формы правки: тронул поле — выезжает кнопка (x-ui.save-bar с data-save-bar="<id формы>"
// или цель bar внутри формы), нажал — обычная отправка формы, экран остаётся тем же. Поля считаются и вне формы, если
// они к ней привязаны атрибутом form= (деньги и метки редактора предложения). partial — карточка строки: уходят только
// тронутые поля (имена в `_fields[]`, OfferRequest::payload), чужие правки остальных не перетираются. dirty — форма
// вернулась с ошибками проверки: кнопка видна сразу, введённое — из old(); sent — прежний `_fields[]` карточки.
// Кнопка с formaction (x-ui.save-bar :action) шлёт ту же форму на другой адрес — сохранить часть формы.
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
        // Черновик формы (draft_controller) вернул набранное до перезагрузки — это несохранённое.
        if (this.element.dataset.dirty === '1') this.touched = true;
        this.show();
    }

    disconnect() {
        document.removeEventListener('input', this.onEdit);
        document.removeEventListener('change', this.onEdit);
        document.removeEventListener('trix-change', this.onTrix);
        this.element.removeEventListener('submit', this.onSubmit);
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
}
