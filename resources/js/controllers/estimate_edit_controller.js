import { Controller } from '@hotwired/stimulus';
import { focusField } from '../keyboard';

// Лист оценки менеджера (11.10.2026) — одна из панелей на виду: «отправлена» (done), выбор корректировки «Согласен /
// Предложить свою» (choose), поле суммы (form) или отказ с желаемой стоимостью (decline). Переключение — без перезагрузки; у поля сумма сразу выделена.
export default class extends Controller {
    static targets = ['pane', 'input', 'wish', 'diff'];

    show({ params: { pane } }) {
        this.paneTargets.forEach((p) => { p.hidden = p.dataset.pane !== pane; });
        // Заголовок листа (deal-body, на телефоне) — у открытой панели свой: «Отказ от оценки» у отказа.
        const title = this.element.parentElement?.querySelector('[data-estimate-title]');
        const open = this.paneTargets.find((p) => p.dataset.pane === pane);
        if (title && open?.dataset.title) title.textContent = open.dataset.title;
        const field = pane === 'form' ? (this.hasInputTarget && this.inputTarget) : pane === 'decline' ? (this.hasWishTarget && this.wishTarget) : null;
        if (field) {
            focusField(field);
            field.select();
        }
    }

    // Разница желаемой к ориентиру (заявленная или цена XCar) по ходу набора: «−100 000», пусто — ничего.
    diff() {
        if (!this.hasDiffTarget || !this.hasWishTarget) return;
        const wish = Number(this.wishTarget.value.replace(/[,.]\d{1,2}\s*$/, '').replace(/\D+/g, ''));
        const delta = wish - Number(this.diffTarget.dataset.mark);
        this.diffTarget.textContent = wish && delta ? `${delta > 0 ? '+' : '−'}${Math.abs(delta).toLocaleString('ru-RU')}` : '';
    }
}
