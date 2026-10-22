import { Controller } from '@hotwired/stimulus';

// «Куда везти» (`x-logistics.route-fields`): сначала город, потом место. Видны наши парковки выбранного города (города
// нет — все); отмеченная строка не прячется. Выбрали город из справочника — дальность той же формы встаёт сама: регион
// города тот же, что у задачи (Москва с областью и Петербург с Ленинградской — один, как `Reach::area`), — «Внутри
// региона», другой — «Между регионами».
const AREA = { 50: 77, 47: 78 };
const area = (region) => AREA[region] ?? region;

export default class extends Controller {
    static targets = ['place'];
    static values = { region: Number };

    connect() {
        this.sync();
    }

    city() {
        return this.element.querySelector('input[name="to_city_id"]')?.value || '';
    }

    sync() {
        const city = this.city();
        for (const row of this.placeTargets) {
            const checked = row.querySelector('input')?.checked;
            row.hidden = city !== '' && row.dataset.city !== city && !checked;
        }
    }

    chosen(event) {
        const region = Number(event.detail?.region || 0);
        this.sync();
        if (!region || !this.regionValue) return;
        const reach = this.element.closest('form')?.querySelector(`input[name="reach"][value="${area(region) === this.regionValue ? 'local' : 'intercity'}"]`);
        if (reach && !reach.checked) {
            reach.checked = true;
            reach.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}
