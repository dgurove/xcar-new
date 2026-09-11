import { Controller } from '@hotwired/stimulus';

// Шторка на <dialog>. Открывается кнопкой в том же контроллере, событием
// (например deal:open@window) или сразу, если сервер вернул форму с ошибками
// (data-sheet-open-value). inflow — медиазапрос, при котором диалог стоит в
// потоке карточкой, а не шторкой (блок сделки на широком экране).
export default class extends Controller {
    static targets = ['dialog'];
    static values = { inflow: String };

    connect() {
        if (this.hasInflowValue && this.inflowValue) {
            this.media = matchMedia(this.inflowValue);
            this.onMedia = () => this.place();
            this.media.addEventListener('change', this.onMedia);
            this.place();
        }
        if (this.dialogTarget.dataset.sheetOpenValue === 'true') this.open();
    }

    disconnect() {
        this.media?.removeEventListener('change', this.onMedia);
        if (this.dialogTarget.open) this.dialogTarget.close();
    }

    // В потоке — открыт немодально; иначе закрыт до вызова open().
    place() {
        const d = this.dialogTarget;
        if (this.media.matches) {
            if (d.open) d.close();
            d.show();
        } else if (d.open && !d.matches(':modal')) {
            d.close();
        }
    }

    open() {
        if (this.media?.matches) return;
        const d = this.dialogTarget;
        if (d.open) d.close();
        d.showModal();
        d.querySelector('[autofocus], input:not([type=hidden]), textarea, select')?.focus();
    }

    close() {
        this.dialogTarget.close();
        if (this.media?.matches) this.dialogTarget.show();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget && this.dialogTarget.matches(':modal')) this.close();
    }
}
