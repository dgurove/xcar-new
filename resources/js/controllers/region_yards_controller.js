import { Controller } from '@hotwired/stimulus';

// «Куда везти» (`x-logistics.route-fields`): «Внутри региона» — только города (с парковками) зоны задачи, «Между регионами» и зона
// неизвестна — все. Дальность — сегмент `reach` той же формы (у «Забрал» его нет — берётся дальность задачи). Город с
// отмеченной строкой не прячется: её выбрали руками.
export default class extends Controller {
    static targets = ['group'];
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
        for (const group of this.groupTargets) {
            const checked = group.querySelector('input:checked');
            group.hidden = local && this.areaValue > 0 && Number(group.dataset.area) !== this.areaValue && !checked;
        }
    }
}
