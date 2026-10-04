import { Controller } from '@hotwired/stimulus';

// Галочки строк «Оцененных» и «Публикации»: «Выбрать все» (только видимые — с учётом чипов), галочка группы
// (слот целиком), число на кнопках и плашка действий снизу, пока выбрано хоть одно. Не готовые к продаже строки
// галочки не имеют вовсе — выбрать их нельзя. Живое обновление списка (морф Turbo) выбор не сбрасывает: галочки —
// data-turbo-permanent, а плашка и числа пересчитываются после морфа.
export default class extends Controller {
    static targets = ['box', 'all', 'group', 'bar', 'count'];

    connect() {
        this.onMorph = () => this.sync();
        document.addEventListener('turbo:morph', this.onMorph);
        this.sync();
    }

    disconnect() { document.removeEventListener('turbo:morph', this.onMorph); }

    boxTargetConnected() { this.sync(); }
    boxTargetDisconnected() { this.sync(); }

    get live() { return this.boxTargets.filter((b) => !b.disabled); }

    pickAll(event) {
        const on = event.target.checked;
        this.live.forEach((b) => { b.checked = on; });
        this.sync();
    }

    pickGroup(event) {
        const on = event.target.checked, group = event.target.dataset.group;
        this.live.filter((b) => b.dataset.group === group).forEach((b) => { b.checked = on; });
        this.sync();
    }

    sync() {
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
