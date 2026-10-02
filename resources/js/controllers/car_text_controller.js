import { Controller } from '@hotwired/stimulus';
import { filler, wait } from '../fill.js';

// Текст про машину кучей (x-ui.paste): вставили — сервер раскладывает его по полям (`CarText`), поля формы заполняются
// по очереди и вспыхивают. Вставленное перезаписывает то, что в полях: человек сам принёс этот текст. Набранное
// руками — по ✨ или ⌘Enter. Цена — в закупочную (CRM) или в оценочную стоимость (парковка, если поле есть у вендора).
export default class extends Controller {
    static targets = ['input', 'button'];
    static values = { url: { type: String, default: '/reference/car-text' } };

    check() {
        this.buttonTarget.disabled = this.inputTarget.value.trim() === '';
    }

    pasted() {
        // Значение поля появляется после события вставки.
        setTimeout(() => { this.check(); this.parse(); }, 0);
    }

    async parse(event) {
        event?.preventDefault();
        const text = this.inputTarget.value.trim();
        if (!text || this.busy) return;
        this.busy = true;
        const box = this.inputTarget.parentElement;
        this.buttonTarget.classList.add('is-busy');
        box.classList.add('is-scanning');
        const started = Date.now();

        let data = null;
        try {
            const r = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
                body: JSON.stringify({ text }),
            });
            if (r.ok) data = await r.json();
        } catch {}
        await wait(Math.max(0, 500 - (Date.now() - started)));
        box.classList.remove('is-scanning');
        this.buttonTarget.classList.remove('is-busy');
        this.busy = false;
        if (!data) { window.toast?.('Справочник не ответил', 'danger'); return; }

        const form = this.element.closest('form');
        const v = data.values || {};
        const fill = filler(form, { overwrite: true });
        fill.plain('vendor_id', v.vendor_id);
        fill.plain('claim_ref', v.claim_ref);
        fill.plain('ref', v.claim_ref);
        fill.combo('brand_id', v.brand_id, v.brand);
        fill.combo('model_id', v.model_id, v.model);
        fill.plain('vin', v.vin);
        fill.plain('plate', v.plate);
        fill.plain('year', v.year);
        fill.plain('mileage', v.mileage);
        fill.plain('color', v.color);
        fill.plain('body', v.body);
        fill.plain('transmission', v.transmission);
        fill.plain('drive', v.drive);
        fill.plain('fuel', v.fuel);
        fill.plain('engine_volume', v.engine_volume);
        fill.plain('engine_power', v.engine_power);
        fill.combo('settlement_id', v.settlement_id, v.city);
        fill.plain('inspection_address', v.inspection_address);
        fill.plain('contact_name', v.contact_name);
        fill.plain('contact_phone', v.contact_phone);
        const value = form.querySelector('[name="value"]');
        if (form.querySelector('[name="floor_price"]')) fill.plain('floor_price', v.price);
        else if (value && !value.closest('[hidden]')) fill.plain('value', v.price);

        if (!fill.count) { window.toast?.('В тексте не нашлось ничего для полей'); return; }
        await fill.run();
        // Сменили марку, а модели в тексте нет — прежняя модель чужой марки не остаётся.
        const model = form.querySelector('[name="model_id"]');
        if (v.brand_id && !v.model_id && model) {
            model.value = '';
            const shown = form.querySelector('#f-model_id');
            if (shown) shown.value = '';
        }
    }
}
