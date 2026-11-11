import { Controller } from '@hotwired/stimulus';

// Сетка «день × час» подписания (`x-deal.signing-slots`, 11.11.2026): на кнопке — сколько слотов отмечено («Отправить, 3»),
// без них она неактивна; «Весь день» отмечает часы дня (все отмечены — снимает); ряд часов, что листается вбок, затухает справа,
// пока есть куда листать (`is-end` — дошли до конца или всё влезло).
export default class extends Controller {
    static targets = ['day', 'row', 'submit'];
    static values = { label: String };

    connect() {
        this.update();
        this.rowTargets.forEach((row) => this.mark(row));
        this.resize = () => this.rowTargets.forEach((row) => this.mark(row));
        window.addEventListener('resize', this.resize, { passive: true });
    }

    disconnect() {
        window.removeEventListener('resize', this.resize);
    }

    update() {
        const n = this.element.querySelectorAll('input[name="slots[]"]:checked').length;
        this.submitTarget.disabled = n === 0;
        this.submitTarget.textContent = n ? `${this.labelValue}, ${n}` : this.labelValue;
        for (const day of this.dayTargets) {
            const boxes = [...day.querySelectorAll('input[name="slots[]"]')];
            day.querySelector('.slot-all')?.classList.toggle('is-on', boxes.length > 0 && boxes.every((b) => b.checked));
        }
    }

    all(event) {
        const boxes = [...event.currentTarget.closest('[data-slots-pick-target="day"]').querySelectorAll('input[name="slots[]"]')];
        const on = !boxes.every((b) => b.checked);
        boxes.forEach((b) => { b.checked = on; });
        this.update();
    }

    edge(event) {
        this.mark(event.currentTarget);
    }

    mark(row) {
        row.classList.toggle('is-end', row.scrollLeft + row.clientWidth >= row.scrollWidth - 2);
    }
}
