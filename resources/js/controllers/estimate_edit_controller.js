import { Controller } from '@hotwired/stimulus';

// Лист оценки менеджера (11.10.2026) — одна из панелей на виду: «отправлена» (done), выбор корректировки «Согласен /
// Предложить свою» (choose), поле суммы (form) или отказ с желаемой стоимостью (decline). Переключение — без перезагрузки; у поля сумма сразу выделена.
export default class extends Controller {
    static targets = ['pane', 'input', 'wish'];

    show({ params: { pane } }) {
        this.paneTargets.forEach((p) => { p.hidden = p.dataset.pane !== pane; });
        const field = pane === 'form' ? (this.hasInputTarget && this.inputTarget) : pane === 'decline' ? (this.hasWishTarget && this.wishTarget) : null;
        if (field) {
            field.focus();
            field.select();
        }
    }
}
