import { Controller } from '@hotwired/stimulus';

// Сумма разрядами прямо при наборе: «1 450 000» читается с первого взгляда, а не «1450000». Сервер пробелы снимает
// (OfferRequest, VehicleFields::clean). Курсор остаётся после той же цифры, что и до форматирования.
// Копейки — у полей с inputmode="decimal" или data-digits-decimals-value: одна запятая (точка при наборе становится
// запятой), после неё не больше двух цифр, разряды только у целой части. Запятую сервер понимает (Money::parse).
export default class extends Controller {
    static values = { decimals: Number };

    // Пришедшее с сервера число — тоже разрядами, но поле не «изменено»: черновик и морф сверяют value с defaultValue.
    connect() {
        const pristine = this.element.value === this.element.defaultValue;
        this.format();
        if (pristine) this.element.defaultValue = this.element.value;
    }

    get places() {
        if (this.hasDecimalsValue) return this.decimalsValue;
        return this.element.inputMode === 'decimal' ? 2 : 0;
    }

    format() {
        const el = this.element, before = el.value, places = this.places;
        const caret = el.selectionStart ?? before.length;
        // Значимое — цифры и одна запятая; kept — сколько значимого стояло до курсора.
        let int = '', frac = '', comma = false, kept = 0;
        for (let i = 0; i < before.length; i++) {
            const ch = before[i];
            let took = false;
            if (/\d/.test(ch)) {
                if (!comma) { int += ch; took = true; } else if (frac.length < places) { frac += ch; took = true; }
            } else if (places > 0 && !comma && (ch === ',' || ch === '.')) {
                comma = took = true;
            }
            if (took && i < caret) kept++;
        }
        // «,5» → «0,5»: ноль перед запятой — тоже значимое, курсор за запятой сдвигается на него.
        if (comma && int === '') { int = '0'; if (kept > 0) kept++; }
        const out = int.replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + (comma ? ',' + frac : '');
        if (out === before) return;
        el.value = out;
        if (document.activeElement !== el) return;
        let pos = 0;
        for (let seen = 0; pos < out.length && seen < kept; pos++) if (out[pos] !== ' ') seen++;
        el.setSelectionRange(pos, pos);
    }
}
