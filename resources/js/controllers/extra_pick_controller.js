import { Controller } from '@hotwired/stimulus';

// «Доп. запрос…» в подтверждении (27.11.2026, `x-deal.want-sheet bid`): галки шторки уходят с формой подтверждения; под
// формой — «+ Доп. запрос…», пока ничего не отмечено, иначе строка «Запросить: ПТС, осмотр ×». Прошлые галки менеджера
// (`last`) ставятся при первом открытии шторки, а не молча с формой.
export default class extends Controller {
    static targets = ['box', 'add', 'line', 'summary'];
    static values = { last: Array };

    connect() { this.sync(); }

    open() {
        if (!this.opened && !this.boxTargets.some((b) => b.checked)) {
            this.boxTargets.forEach((b) => { b.checked = this.lastValue.includes(Number(b.value)); });
        }
        this.opened = true;
        this.sync();
    }

    sync() {
        const words = this.boxTargets.filter((b) => b.checked).map((b) => b.dataset.word);
        // Сокращение («ПТС», «ДКП») строчной не становится.
        const low = (w) => (/^\p{Lu}{2}/u.test(w) ? w : w.charAt(0).toLowerCase() + w.slice(1));
        if (this.hasSummaryTarget) this.summaryTarget.textContent = words.map((w, i) => (i ? low(w) : w)).join(', ');
        if (this.hasLineTarget) this.lineTarget.hidden = !words.length;
        if (this.hasAddTarget) this.addTarget.hidden = !!words.length;
    }

    clear() {
        this.boxTargets.forEach((b) => { b.checked = false; });
        this.sync();
    }
}
