import { Controller } from '@hotwired/stimulus';
import { filler, wait } from '../fill.js';

// Поле VIN с ✨ (x-ui.vin): кнопка загорается на семнадцатом знаке, по клику
// (или Enter в поле) декодер заполняет пустые поля формы — марку, модель, год,
// кузов, КПП, привод, топливо, объём, мощность. Что уже заполнено руками, не
// трогается. Поля заполняются по очереди и вспыхивают; сообщение — только
// о том, чего в полях не видно (модель не нашлась, год не читается).
const ALLOWED = /[^ABCDEFGHJKLMNPRSTUVWXYZ0-9]/g;

export default class extends Controller {
    static targets = ['input', 'button'];
    static values = { url: { type: String, default: '/reference/vin' } };

    connect() {
        if (this.hasInputTarget) this.check();
    }

    check() {
        const input = this.inputTarget;
        const clean = input.value.toUpperCase().replace(ALLOWED, '');
        if (clean !== input.value) {
            const at = input.selectionStart;
            input.value = clean;
            try { input.setSelectionRange(at, at); } catch {}
        }
        const ready = clean.length === 17;
        this.buttonTarget.disabled = !ready;
        if (ready && !this.buttonTarget.classList.contains('is-ready')) this.buttonTarget.classList.add('is-ready');
        if (!ready) this.buttonTarget.classList.remove('is-ready');
    }

    enter(event) {
        event.preventDefault();
        if (!this.buttonTarget.disabled) this.fill();
    }

    async fill() {
        if (this.busy) return;
        const vin = this.inputTarget.value;
        if (vin.length !== 17) return;
        this.busy = true;
        const box = this.inputTarget.parentElement;
        this.buttonTarget.classList.add('is-busy');
        box.classList.add('is-scanning');
        const started = Date.now();

        let data;
        try {
            const r = await fetch(`${this.urlValue}?vin=${encodeURIComponent(vin)}`, { headers: { Accept: 'application/json' } });
            data = await r.json();
        } catch {
            data = null;
        }
        // Полоса должна успеть пройти хотя бы раз — иначе это мигание, а не поиск.
        await wait(Math.max(0, 700 - (Date.now() - started)));
        box.classList.remove('is-scanning');
        this.buttonTarget.classList.remove('is-busy');
        this.busy = false;

        if (!data) { this.shake(); window.toast?.('Справочник не ответил', 'danger'); return; }
        if (!data.valid) { this.shake(); window.toast?.('VIN не разбирается: семнадцать знаков, без букв I, O и Q', 'danger'); return; }

        const v = data.values;
        const fill = filler(this.element);
        const brand = this.element.querySelector('[name="brand_id"]');
        fill.combo('brand_id', v.brand_id, v.brand);
        // Модель — только к своей марке: в форме BMW модели Haval не место.
        if (brand && (brand.value === '' || String(brand.value) === String(v.brand_id))) fill.combo('model_id', v.model_id, v.model);
        fill.plain('year', v.year);
        fill.plain('body', v.body);
        fill.plain('transmission', v.transmission);
        fill.plain('drive', v.drive);
        fill.plain('fuel', v.fuel);
        // Объём в форме — литрами («1,6»), из VIN приходит в см³.
        fill.plain('engine_volume', v.engine_volume ? (v.engine_volume / 1000).toFixed(1).replace('.', ',') : v.engine_volume);
        fill.plain('engine_power', v.engine_power);

        // Что заполнилось — видно по вспышкам полей; словами — только то, чего в них не видно.
        if (!fill.count) this.shake();
        await fill.run();
        if (data.skipped?.length) window.toast?.(data.skipped.join('; '));
    }

    shake() {
        const b = this.buttonTarget;
        b.classList.remove('is-shaking');
        void b.offsetWidth;
        b.classList.add('is-shaking');
        b.addEventListener('animationend', () => b.classList.remove('is-shaking'), { once: true });
    }
}

