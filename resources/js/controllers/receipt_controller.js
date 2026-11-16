import { Controller } from '@hotwired/stimulus';

// «Приложить чек» на шаге оплаты (16.11.2026, вариант Б): файл выбран — оплата одна, отправляем сразу; две — шторка «За что
// этот чек?» со снимком, «Приложить» живая после выбора строки (`cabinet.deals.receipt`).
export default class extends Controller {
    static targets = ['input', 'preview', 'name', 'submit'];
    static values = { single: Boolean };

    picked() {
        const file = this.inputTarget.files?.[0];
        if (!file) return;
        if (this.singleValue) {
            this.element.requestSubmit();
            return;
        }
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
        const image = file.type.startsWith('image/') && !/heic/i.test(file.type);
        this.previewUrl = image ? URL.createObjectURL(file) : null;
        this.previewTarget.hidden = !image;
        if (image) this.previewTarget.src = this.previewUrl;
        this.nameTarget.textContent = file.name;
        this.nameTarget.hidden = image;
        for (const r of this.element.querySelectorAll('input[name="for"]')) r.checked = false;
        this.submitTarget.disabled = true;
        this.dispatch('ask');
    }

    chose() {
        this.submitTarget.disabled = false;
    }

    // Каждое «Приложить чек» — выбор заново: тот же файл после закрытой шторки иначе не дал бы `change`.
    reset() {
        this.inputTarget.value = '';
    }

    disconnect() {
        if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
    }
}
