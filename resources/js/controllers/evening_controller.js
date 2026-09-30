import { Controller } from '@hotwired/stimulus';

// «Приём подтверждений до»: выбрали день — время по умолчанию 20:00, а не полночь, которую подставляет календарь.
// Время, поставленное руками, не трогаем: меняется только ровно 00:00.
export default class extends Controller {
    fix() {
        const v = this.element.value;
        if (/T00:00$/.test(v)) this.element.value = v.replace(/T00:00$/, 'T20:00');
    }
}
