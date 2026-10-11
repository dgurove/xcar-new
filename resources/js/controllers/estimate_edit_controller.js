import { Controller } from '@hotwired/stimulus';

// Лист оценки менеджера (11.10.2026) — одна из панелей на виду: «отправлена» (done), выбор корректировки «Согласен /
// Предложить свою» (choose) или поле суммы (form). Переключение — без перезагрузки; у поля сумма сразу выделена.
export default class extends Controller {
    static targets = ['pane', 'input'];

    show({ params: { pane } }) {
        this.paneTargets.forEach((p) => { p.hidden = p.dataset.pane !== pane; });
        if (pane === 'form' && this.hasInputTarget) {
            this.inputTarget.focus();
            this.inputTarget.select();
        }
    }
}
