import { Controller } from '@hotwired/stimulus';

// Наша цена в строке машины закупки: разделители на ходу, сохраняется сама на
// уходе с поля, Enter («Далее» на телефоне) — сохранить и перейти к следующей
// машине. Галочка в поле держится полторы секунды после ответа сервера.
export default class extends Controller {
    static targets = ['field', 'mark'];

    connect() {
        this.rubles = new Intl.NumberFormat('ru-RU');
        this.saved = this.fieldTarget.value;
    }

    input() {
        const digits = this.fieldTarget.value.replace(/\D/g, '');
        this.fieldTarget.value = digits ? this.rubles.format(Number(digits)) : '';
    }

    next(event) {
        event.preventDefault();
        this.save();
        const fields = [...document.querySelectorAll('[data-price-target="field"]')];
        const after = fields[fields.indexOf(this.fieldTarget) + 1];
        if (after) after.focus(); else this.fieldTarget.blur();
    }

    async save() {
        const value = this.fieldTarget.value;
        if (value === this.saved) return;
        const form = new FormData(this.element);
        form.set('price_final', value.replace(/\D/g, ''));
        try {
            const r = await fetch(this.element.action, { method: 'POST', body: form, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, Accept: 'application/json' } });
            if (!r.ok) throw new Error(`http ${r.status}`);
            this.saved = value;
            this.markTarget.hidden = false;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { this.markTarget.hidden = true; }, 1500);
        } catch {
            window.toast?.('Не сохранилось', 'danger');
            this.fieldTarget.value = this.saved;
        }
    }
}
