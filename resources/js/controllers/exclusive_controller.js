import { Controller } from '@hotwired/stimulus';
import { confirmSheet } from '../confirm';

// «Только ему до …» в шторке «Показ» (20.11.2026): снять эксклюзив за оценку — после «Вы уверены?»; скрытое `exclusive_off`
// уходит с формой, волны тогда встают как выбрали (`UpdateOffer`). «Отмена» — переключатель обратно.
export default class extends Controller {
    static targets = ['toggle', 'off'];
    static values = { text: String };

    async change() {
        if (this.toggleTarget.checked) return this.set('0');
        const ok = await confirmSheet('Вы уверены?', { text: this.textValue, label: 'Да, снять', danger: true });
        if (ok) this.set('1');
        else this.toggleTarget.checked = true;
    }

    set(v) {
        this.offTarget.value = v;
        this.offTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
