import { Controller } from '@hotwired/stimulus';

// Шторка «Вендор письма» (x-mail.vendor-sheet): одна на страницу, событие vendor-pick:open приносит адрес формы
// (`{base}/{ветка}/vendor`), отправителя, догадку названия и домен (`App\Mail\VendorPick`). Кнопка называет исход:
// «Отнести к «Альфа»» / «Завести «ТИТ»»; пока не выбрано — неактивна.
export default class extends Controller {
    static targets = ['form', 'action', 'sender', 'senderInput', 'name', 'domainRow', 'domainLabel', 'domainBox', 'item', 'submit'];

    connect() {
        this.label();
    }

    fill({ detail }) {
        if (!detail?.url) return;
        this.formTarget.reset();
        this.formTarget.action = detail.url;
        this.actionTarget.value = detail.url;
        this.senderTarget.textContent = detail.sender || '';
        this.senderInputTarget.value = detail.sender || '';
        const name = this.nameField();
        if (name) name.value = detail.name || '';
        this.domainRowTarget.hidden = !detail.domain;
        this.domainBoxTarget.checked = false;
        if (detail.domain) this.domainLabelTarget.textContent = `Все адреса @${detail.domain}`;
        this.itemTargets.forEach((el) => { el.hidden = false; });
        // Сегмент «Выбрать» и его панель — заново: reset() вернул радио, панели переключает reveal.
        this.formTarget.querySelector('input[name="mode"][value="pick"]')?.dispatchEvent(new Event('change', { bubbles: true }));
        this.label();
    }

    filter(event) {
        const q = event.target.value.trim().toLowerCase();
        this.itemTargets.forEach((el) => { el.hidden = q !== '' && !el.dataset.name.includes(q); });
    }

    // Enter в поиске не отправляет форму: вендор ещё не выбран.
    stop(event) {
        event.preventDefault();
    }

    label() {
        const form = this.formTarget;
        const mode = form.querySelector('input[name="mode"]:checked')?.value || 'pick';
        let text = 'Выберите вендора';
        let ready = false;
        if (mode === 'pick') {
            const picked = form.querySelector('input[name="vendor_id"]:checked');
            if (picked) { text = `Отнести к «${picked.dataset.label}»`; ready = true; }
        } else {
            const name = this.nameField()?.value.trim() || '';
            text = name ? `Завести «${name}»` : 'Завести вендора';
            ready = name !== '';
        }
        const button = this.button();
        if (!button) return;
        button.textContent = text;
        button.disabled = !ready;
    }

    nameField() {
        return this.formTarget.querySelector('input[name="name"]');
    }

    button() {
        return this.formTarget.querySelector('#vendor-pick-submit, [data-vendor-pick-target="submit"]');
    }
}
