import { Controller } from '@hotwired/stimulus';
import { reducedMotion } from '../lib/motion';

// Общий такт для кадров всех карточек: раз в пять секунд, пока вкладка видна.
// cards:stop гасит его до перезагрузки страницы. Такт один на страницу, сколько бы контейнеров плиток ни было
// (ленты пачек — у каждой свой контейнер): иначе кадры листались бы по разу на контейнер.
let users = 0;
let interval = null;
let stopped = false;

// Пока страницу листают или палец на экране (и секунду после), такт пропускается: плавная прокрутка ленты кадров и
// подгрузка следующего кадра посреди жеста давали рывки (15.10.2026). Прокрутка — любая, и ленты вбок тоже (`capture`:
// scroll элемента не всплывает, без него инерция ленты пачки такт не держала).
let movedAt = 0;
addEventListener('scroll', () => { movedAt = performance.now(); }, { capture: true, passive: true });
addEventListener('touchstart', () => { movedAt = performance.now(); }, { capture: true, passive: true });
addEventListener('touchend', () => { movedAt = performance.now(); }, { capture: true, passive: true });

const onStop = () => { stopped = true; clearInterval(interval); interval = null; };
window.addEventListener('cards:stop', onStop);
// Новая страница — такт снова живой (Turbo не перезагружает модуль).
document.addEventListener('turbo:load', () => { stopped = false; });

export default class extends Controller {
    connect() {
        if (reducedMotion()) return;
        users++;
        this.counted = true;
        if (!interval && !stopped) {
            interval = setInterval(() => {
                if (!document.hidden && performance.now() - movedAt > 1000) window.dispatchEvent(new CustomEvent('cards:tick'));
            }, 5000);
        }
    }

    disconnect() {
        if (!this.counted) return;
        this.counted = false;
        if (--users === 0) { clearInterval(interval); interval = null; }
    }
}
