import { Controller } from '@hotwired/stimulus';

// Тревога «нет покупателя» в шапке: шторка со списком машин перечитывается при каждом открытии — список живой.
export default class extends Controller {
    static targets = ['frame'];

    open() {
        const frame = this.frameTarget;
        if (frame.complete) frame.reload(); else frame.loading = 'eager';
    }
}
