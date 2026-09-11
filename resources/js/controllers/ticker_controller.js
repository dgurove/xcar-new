import { Controller } from '@hotwired/stimulus';

// Общий такт для кадров всех карточек: раз в пять секунд, пока вкладка видна.
// cards:stop гасит его до перезагрузки страницы.
export default class extends Controller {
    connect() {
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        this.onStop = () => clearInterval(this.interval);
        window.addEventListener('cards:stop', this.onStop);
        this.interval = setInterval(() => {
            if (!document.hidden) window.dispatchEvent(new CustomEvent('cards:tick'));
        }, 5000);
    }

    disconnect() {
        clearInterval(this.interval);
        window.removeEventListener('cards:stop', this.onStop);
    }
}
