import { Controller } from '@hotwired/stimulus';

// Галочки строк «Оцененных», «Публикации» и «Без продажной цены»: «Выбрать все» (только видимые — с учётом чипов), галочка группы
// (слот целиком), число на кнопках и плашка действий снизу, пока выбрано хоть одно. Не готовые к продаже строки
// галочки не имеют вовсе — выбрать их нельзя. Живое обновление списка (морф Turbo) выбор не сбрасывает: галочки —
// data-turbo-permanent, а плашка и числа пересчитываются после морфа. Форма в шторке без галочек своей формы
// (логистика: «Назначить», «Отменить») берёт выбранные событием formdata — `data-action="formdata->pick#carry"`.
// Выбор помнится значениями (`chosen`): строку, открытую в карточке, поток Turbo заменяет целиком, и `data-turbo-permanent`
// у потока не работает — новая галочка встаёт отмеченной, если её строку выбирали (18.10.2026, логистика).
export default class extends Controller {
    static targets = ['box', 'all', 'group', 'bar', 'count'];

    connect() {
        this.chosen = new Set(this.boxTargets.filter((b) => b.checked).map((b) => this.key(b)));
        // Режим «Выбрать» (`data-pick-mode`, 22.11.2026, начальник: «обычно сверху кнопка „Выбрать“, и только после неё
        // галочки»): вне режима галочек не видно (app.css), строка открывает окно. Морф снимает атрибут — возвращаем.
        this.selecting = this.element.hasAttribute('data-selecting');
        this.onMorph = () => { this.paint(); this.sync(); };
        document.addEventListener('turbo:morph', this.onMorph);
        this.paint();
        this.sync();
    }

    disconnect() { document.removeEventListener('turbo:morph', this.onMorph); }

    get modal() { return this.element.hasAttribute('data-pick-mode'); }

    // «Выбрать» / «Готово» в шапке (событие `pick:mode`).
    mode() {
        if (!this.modal) return;
        this.selecting ? this.leave() : this.enter();
    }

    enter() {
        this.selecting = true;
        this.paint();
    }

    // Выход — «Готово», Esc, пачка ушла: выбор снимается, галочки прячутся.
    leave() {
        if (!this.modal || !this.selecting) return;
        this.selecting = false;
        this.boxTargets.forEach((b) => { b.checked = false; });
        this.chosen?.clear();
        this.paint();
        this.sync();
    }

    paint() {
        if (!this.modal) return;
        this.element.toggleAttribute('data-selecting', !!this.selecting);
        document.querySelectorAll('[data-pick-toggle]').forEach((btn) => {
            btn.setAttribute('aria-pressed', this.selecting ? 'true' : 'false');
            if (btn.classList.contains('pill')) btn.classList.toggle('pill-accent', !!this.selecting);
            else {
                btn.classList.toggle('btn-accent', !!this.selecting);
                btn.classList.toggle('btn-quiet', !this.selecting);
            }
            const label = btn.querySelector('[data-pick-toggle-label]');
            if (label) label.textContent = this.selecting ? 'Готово' : 'Выбрать';
        });
    }

    // В режиме нажатие по строке или плитке — её галочка, а не окно (click в фазе захвата: до окна и ссылки строки).
    tap(event) {
        if (!this.modal || !this.selecting) return;
        const row = event.target.closest('[data-detail-key]');
        if (!row || !this.element.contains(row) || event.target.closest('input, label, .pick-cell, .card-check')) return;
        const box = row.querySelector('[data-pick-target~="box"]');
        event.preventDefault();
        event.stopPropagation();
        if (!box || box.disabled) return;
        box.checked = !box.checked;
        this.remember(box);
        this.sync();
    }

    boxTargetConnected(box) {
        if (this.chosen?.has(this.key(box))) box.checked = true;
        this.sync();
    }
    boxTargetDisconnected() { this.sync(); }

    get live() { return this.boxTargets.filter((b) => !b.disabled); }

    key(box) { return `${box.name}|${box.value}`; }

    // Помнит только то, что отметил человек: при замене строки потоком новая галочка уже в DOM, пока старая уходит, —
    // пересчёт из DOM стёр бы выбор раньше, чем новая получит его обратно.
    remember(box) { box.checked ? this.chosen?.add(this.key(box)) : this.chosen?.delete(this.key(box)); }

    pickAll(event) {
        const on = event.target.checked;
        this.live.forEach((b) => { b.checked = on; this.remember(b); });
        this.sync();
    }

    pickGroup(event) {
        const on = event.target.checked, group = event.target.dataset.group;
        this.live.filter((b) => b.dataset.group === group).forEach((b) => { b.checked = on; this.remember(b); });
        this.sync();
    }

    carry(event) {
        this.live.filter((b) => b.checked).forEach((b) => event.formData.append(b.name, b.value));
    }

    // Пачка ушла, а строки остались в списке («Без продажной цены» → на оценку, 22.11.2026): галочки — data-turbo-permanent и
    // пережили бы ответ отмеченными, снимаем их сами. Ошибка формы — выбор остаётся.
    done(event) {
        if (!event.detail?.success) return;
        this.boxTargets.forEach((b) => { b.checked = false; });
        this.chosen?.clear();
        this.selecting = false;
        this.paint();
        this.sync();
    }

    sync(event) {
        if (event?.target?.matches?.('[data-pick-target~="box"]')) this.remember(event.target);
        const live = this.live, n = live.filter((b) => b.checked).length;
        this.countTargets.forEach((c) => { c.textContent = n ? `(${n})` : ''; });
        this.barTargets.forEach((b) => { b.hidden = n === 0; });
        const mark = (box, list) => {
            const k = list.filter((b) => b.checked).length;
            box.checked = k > 0 && k === list.length;
            box.indeterminate = k > 0 && k < list.length;
        };
        this.allTargets.forEach((a) => mark(a, live));
        this.groupTargets.forEach((g) => mark(g, live.filter((b) => b.dataset.group === g.dataset.group)));
    }
}
