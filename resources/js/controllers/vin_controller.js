import { Controller } from '@hotwired/stimulus';

// Поле VIN: как только набрано семнадцать знаков — спросить справочник и
// заполнить пустые поля формы (марка, модель, год, КПП, привод). Что уже
// заполнено руками — не трогается. Итог — сообщением.
export default class extends Controller {
    static values = { url: { type: String, default: '/spravochnik/vin' } };

    async fill(event) {
        const input = event.currentTarget;
        const vin = input.value.replace(/[\s\-_]+/g, '').toUpperCase();
        if (vin.length !== 17 || vin === this.last) return;
        this.last = vin;
        input.value = vin;

        let data;
        try {
            const r = await fetch(`${this.urlValue}?vin=${encodeURIComponent(vin)}`, { headers: { Accept: 'application/json' } });
            data = await r.json();
        } catch {
            return;
        }
        if (!data.valid) { window.toast?.('VIN не разбирается: семнадцать знаков, без букв I, O и Q', 'danger'); return; }

        const form = this.element;
        const set = (name, value) => {
            const el = form.querySelector(`[name="${name}"]`);
            if (!el || value == null || value === '') return false;
            if (el.value !== '' && el.value !== null) return false;
            el.value = value;
            el.dispatchEvent(new Event('change', { bubbles: true }));
            return true;
        };
        const done = [];
        const v = data.values;
        if (v.brand_id && set('brand_id', v.brand_id)) {
            const text = form.querySelector('#f-brand_id');
            if (text) text.value = v.brand || '';
            done.push(`марка — ${v.brand}`);
        }
        if (v.model_id && set('model_id', v.model_id)) {
            const text = form.querySelector('#f-model_id');
            if (text) text.value = v.model || '';
            done.push(`модель — ${v.model}`);
        }
        if (v.year && set('year', v.year)) done.push(`год — ${v.year}`);
        if (v.transmission && set('transmission', v.transmission)) done.push(`КПП — ${v.transmission_label}`);
        if (v.drive && set('drive', v.drive)) done.push(`привод — ${v.drive_label}`);

        const note = data.skipped?.length ? ' · ' + data.skipped.join('; ') : '';
        window.toast?.(done.length ? 'По VIN: ' + done.join(', ') + note : 'Из VIN ничего нового' + note);
    }
}
