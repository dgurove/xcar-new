import { Controller } from '@hotwired/stimulus';

// Общий такт для кадров всех карточек: раз в пять секунд, пока вкладка видна.
// cards:stop гасит его до перезагрузки страницы. Такт один на страницу, сколько бы контейнеров плиток ни было
// (ленты пачек — у каждой свой контейнер): иначе кадры листались бы по разу на контейнер.
let users = 0;
let interval = null;
let stopped = false;

const onStop = () => { stopped = true; clearInterval(interval); interval = null; };
window.addEventListener('cards:stop', onStop);
// Новая страница — такт снова живой (Turbo не перезагружает модуль).
document.addEventListener('turbo:load', () => { stopped = false; });

export default class extends Controller {
    connect() {
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        users++;
        this.counted = true;
        if (!interval && !stopped) {
            interval = setInterval(() => {
                if (!document.hidden) window.dispatchEvent(new CustomEvent('cards:tick'));
            }, 5000);
        }
    }

    disconnect() {
        if (!this.counted) return;
        this.counted = false;
        if (--users === 0) { clearInterval(interval); interval = null; }
    }
}
