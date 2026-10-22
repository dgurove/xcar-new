import { Controller } from '@hotwired/stimulus';

// «Куда везти» (`x-logistics.route-fields`): «Внутри региона» — только парковки зоны задачи, «Между регионами» и зона
// неизвестна — все. Дальность — сегмент `reach` той же формы (у «Забрал» его нет — берётся дальность задачи). Отмеченная
// строка не прячется: её выбрали руками.
export default class extends Controller {
    static targets = ['row'];
    static values = { area: Number, reach: String };

    connect() {
        this.form = this.element.closest('form');
        this.onChange = (e) => { if (e.target.name === 'reach') this.sync(); };
        this.form?.addEventListener('change', this.onChange);
        this.sync();
    }

    disconnect() {
        this.form?.removeEventListener('change', this.onChange);
    }

    sync() {
        const picked = this.form?.querySelector('input[name="reach"]:checked');
        const local = (picked ? picked.value : this.reachValue) === 'local';
        for (const row of this.rowTargets) {
            const checked = row.querySelector('input')?.checked;
            row.hidden = local && this.areaValue > 0 && Number(row.dataset.area) !== this.areaValue && !checked;
        }
    }
}
