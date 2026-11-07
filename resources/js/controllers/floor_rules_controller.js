import { Controller } from '@hotwired/stimulus';
import { nums, rubles } from '../lib/money';

// Диапазоны наценки (x-offer.floor-rules): строки → ступени [до, ₽, %] в скрытое поле и событие floor-rules:change —
// закупочную пересчитывает value-floor. Проверка та же, что на сервере (`Pricing::clean`): границы растут, последняя —
// «дальше». Пока ступени не сходятся, поле не меняется, под строками — словами, что не так.

export default class extends Controller {
    static targets = ['input', 'list', 'row', 'template', 'error', 'reset'];
    static values = { default: Array };

    rules() {
        const rows = this.rowTargets;
        return rows.map((row, i) => {
            const last = i === rows.length - 1;
            const amount = row.querySelector('[data-role="amount"]').value.trim();
            const pct = row.querySelector('[data-role="unit"]').value === 'pct';
            const below = last ? null : rubles(row.querySelector('[data-role="below"]').value);
            return [below, pct ? 0 : (rubles(amount) ?? 0), pct ? (parseFloat(amount.replace(',', '.')) || 0) : 0];
        });
    }

    problem(rules) {
        const edges = rules.slice(0, -1).map(([below]) => below);
        if (edges.some((b) => !b)) return 'Впишите, до какой оценочной действует диапазон';
        if (edges.some((b, i) => i && b <= edges[i - 1])) return 'Границы диапазонов должны расти сверху вниз';
        if (rules.some(([, , pct]) => pct > 100)) return 'Наценка в процентах — не больше 100';
        return null;
    }

    change() {
        this.relabel();
        const rules = this.rules();
        const problem = this.problem(rules);
        this.errorTarget.hidden = !problem;
        this.errorTarget.textContent = problem ?? '';
        if (problem) return;
        const json = JSON.stringify(rules);
        if (this.inputTarget.value === json) return;
        this.inputTarget.value = json;
        // Тронутое поле — для «Сохранить изменения» (save_bar слушает input) и пересчёт закупочной.
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.dispatch('change', { detail: { rules }, prefix: 'floor-rules' });
        if (this.hasResetTarget) this.resetTarget.hidden = json === JSON.stringify(this.defaultValue);
    }

    // Наценку рублями — с разрядами, когда ушли из поля (процент — как вписан).
    tidy(e) {
        const row = e.target.closest('[data-floor-rules-target="row"]');
        if (row.querySelector('[data-role="unit"]').value !== 'rub') return;
        const n = rubles(e.target.value);
        e.target.value = n === null ? '' : nums(n);
    }

    // Новый диапазон встаёт перед «дальше»: его граница — пустая, её вписывают.
    add() {
        const row = this.templateTarget.content.firstElementChild.cloneNode(true);
        const last = this.rowTargets.at(-1);
        last ? this.listTarget.insertBefore(row, last) : this.listTarget.append(row);
        this.relabel();
        row.querySelector('[data-role="below"]')?.focus();
    }

    remove(e) {
        if (this.rowTargets.length < 2) return;
        e.currentTarget.closest('[data-floor-rules-target="row"]').remove();
        this.change();
    }

    reset() {
        this.listTarget.replaceChildren(...this.defaultValue.map(([below, add, pct]) => {
            const row = this.templateTarget.content.firstElementChild.cloneNode(true);
            row.querySelector('[data-role="below"]').value = below ? nums(below) : '';
            row.querySelector('[data-role="amount"]').value = pct ? String(pct).replace('.', ',') : nums(add);
            row.querySelector('[data-role="unit"]').value = pct ? 'pct' : 'rub';
            return row;
        }));
        this.change();
    }

    // Последняя строка — «дальше» без границы; у одной строки — без слова: наценка на любую оценочную.
    relabel() {
        const rows = this.rowTargets;
        rows.forEach((row, i) => {
            const last = i === rows.length - 1;
            row.querySelector('[data-role="word"]').textContent = last ? (rows.length > 1 ? 'дальше' : 'любая') : 'до';
            row.querySelector('[data-role="below"]').hidden = last;
            row.querySelector('[data-action*="floor-rules#remove"]').hidden = rows.length < 2;
        });
    }

    rowTargetConnected() {
        if (this.hasInputTarget) this.relabel();
    }
}
