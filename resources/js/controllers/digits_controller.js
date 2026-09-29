import { Controller } from '@hotwired/stimulus';

// Сумма разрядами прямо при наборе: «1 450 000» читается с первого взгляда, а не «1450000». Сервер пробелы снимает
// (OfferRequest, VehicleFields::clean). Курсор остаётся после той же цифры, что и до форматирования.
export default class extends Controller {
    // Пришедшее с сервера число — тоже разрядами, но поле не «изменено»: черновик и морф сверяют value с defaultValue.
    connect() {
        const pristine = this.element.value === this.element.defaultValue;
        this.format();
        if (pristine) this.element.defaultValue = this.element.value;
    }

    format() {
        const el = this.element, before = el.value;
        const caret = el.selectionStart ?? before.length;
        const kept = before.slice(0, caret).replace(/\D/g, '').length;
        const out = before.replace(/\D/g, '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        if (out === before) return;
        el.value = out;
        if (document.activeElement !== el) return;
        let pos = 0;
        for (let seen = 0; pos < out.length && seen < kept; pos++) if (/\d/.test(out[pos])) seen++;
        el.setSelectionRange(pos, pos);
    }
}
