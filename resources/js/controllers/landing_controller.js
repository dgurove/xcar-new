import { Controller } from '@hotwired/stimulus';

// Лендинг: пока листаешь, первый экран уходит (--lift), а лист с содержимым подходит к шапке (--dock 0…1):
// скругление листа тает, шапка набирает его цвет. У края шапки лист и шапка — одна поверхность.
export default class extends Controller {
    static targets = ['sheet'];

    connect() {
        this.header = document.getElementById('header');
        this.onScroll = () => { cancelAnimationFrame(this.frame); this.frame = requestAnimationFrame(() => this.paint()); };
        addEventListener('scroll', this.onScroll, { passive: true });
        addEventListener('resize', this.onScroll);
        this.paint();
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        removeEventListener('scroll', this.onScroll);
        removeEventListener('resize', this.onScroll);
        this.header?.style.removeProperty('--dock');
    }

    paint() {
        const bar = this.header?.offsetHeight ?? 0;
        const top = this.sheetTarget.getBoundingClientRect().top - bar;
        // Скругление начинает таять за 120 px до шапки.
        const dock = Math.min(1, Math.max(0, 1 - top / 120));
        const lift = Math.min(1, Math.max(0, scrollY / (innerHeight * 0.7)));
        this.element.style.setProperty('--dock', dock.toFixed(3));
        this.element.style.setProperty('--lift', lift.toFixed(3));
        this.header?.style.setProperty('--dock', dock.toFixed(3));
    }
}
