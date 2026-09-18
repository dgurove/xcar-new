import { Controller } from '@hotwired/stimulus';

// Чек-лист кадров при приёме: плитка слота открывает камеру, кадр уходит тем же
// photos-контроллером карточки со слотом и стадией в форме; полоса кадров и
// плитки подменяются ответом сервера.
export default class extends Controller {
    static targets = ['input', 'count'];
    static values = { stage: String, required: Number, have: Number };

    pick(e) {
        this.slot = e.currentTarget.dataset.slot;
        this.inputTarget.click();
    }

    upload() {
        const files = [...this.inputTarget.files];
        this.inputTarget.value = '';
        const photos = this.application.getControllerForElementAndIdentifier(this.element.closest('[data-controller~="photos"]'), 'photos');
        if (!photos || !files.length) return;
        photos.extra = { stage: this.stageValue, slot: this.slot };
        photos.uploadFiles(files).finally(() => { photos.extra = null; });
    }
}
