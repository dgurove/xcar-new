import { Controller } from '@hotwired/stimulus';

// Фокус в поле при открытии — только с мышью: на телефоне клавиатура закрыла бы фото.
export default class extends Controller {
    connect() {
        if (matchMedia('(pointer: fine)').matches) this.element.focus();
    }
}
