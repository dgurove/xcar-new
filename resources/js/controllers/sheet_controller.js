import { Controller } from '@hotwired/stimulus';

// Шторка на <dialog>. Открывается кнопкой в том же контроллере или сразу,
// если сервер вернул форму с ошибками (data-sheet-open-value).
export default class extends Controller {
    static targets = ['dialog'];

    connect() {
        if (this.dialogTarget.dataset.sheetOpenValue === 'true') this.open();
    }

    disconnect() {
        if (this.dialogTarget.open) this.dialogTarget.close();
    }

    open() {
        this.dialogTarget.showModal();
        this.dialogTarget.querySelector('[autofocus], input, textarea, select')?.focus();
    }

    close() {
        this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }
}
