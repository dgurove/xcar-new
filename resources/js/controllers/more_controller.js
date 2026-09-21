import { Controller } from '@hotwired/stimulus';

// «+N» в сетке фото письма: показывает скрытые кадры и убирает сам себя.
export default class extends Controller {
    static targets = ['item', 'button'];

    show(event) {
        event.preventDefault();
        event.stopPropagation();
        for (const item of this.itemTargets) item.classList.remove('hidden');
        this.buttonTarget.remove();
    }
}
