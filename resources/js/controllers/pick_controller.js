import { Controller } from '@hotwired/stimulus';

// Галочки строк «Оцененных» и «Публикации»: «Выбрать все» (только видимые — с учётом чипов), галочка группы
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
        this.onMorph = () => this.sync();
        document.addEventListener('turbo:morph', this.onMorph);
        this.sync();
    }

    disconnect() { document.removeEventListener('turbo:morph', this.onMorph); }

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
