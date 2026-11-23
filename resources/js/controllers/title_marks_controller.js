import { Controller } from '@hotwired/stimulus';

// Значок связи за названием машины (`x-car.title-marks`, 23.11.2026, владелец: «если номер переносится на следующую строку —
// значок возле наименования, если помещается и то и то — после номера»). Группа «номер · ключи · значок» не влезла в строку
// названия — значок переезжает сразу за название; ширина поменялась, морф или шрифт — меряем заново (со значком на месте).
export default class extends Controller {
    static targets = ['links'];

    connect() {
        this.width = 0;
        this.observer = new ResizeObserver(() => this.schedule());
        this.observer.observe(this.element.parentElement);
        // Живой морф возвращает значок в группу (как рисует сервер), шрифт догрузился — перенос мог стать другим: меряем заново.
        this.again = () => { this.width = 0; this.links = this.hasLinksTarget ? this.linksTarget : this.links; this.schedule(); };
        // Значок держим ссылкой: переехав за название, он уже не потомок контроллера, и Stimulus его как цель не видит.
        this.links = this.hasLinksTarget ? this.linksTarget : null;
        this.anchor = this.links?.nextSibling ?? null;
        document.addEventListener('turbo:morph', this.again);
        document.addEventListener('turbo:render', this.again);
        document.fonts?.ready.then(this.again);
        this.schedule();
    }

    disconnect() {
        this.observer?.disconnect();
        document.removeEventListener('turbo:morph', this.again);
        document.removeEventListener('turbo:render', this.again);
        cancelAnimationFrame(this.frame);
    }

    schedule() {
        cancelAnimationFrame(this.frame);
        this.frame = requestAnimationFrame(() => this.place());
    }

    place() {
        const width = this.element.parentElement?.clientWidth ?? 0;
        const links = this.links?.isConnected ? this.links : (this.hasLinksTarget ? this.linksTarget : null);
        if (!links || width === this.width) return;
        this.width = width;
        this.links = links;
        // Название — сосед слева; значок, переехавший к нему, стоит между ними.
        let title = this.element.previousElementSibling;
        if (title === links) title = title.previousElementSibling;
        if (!title) return;
        // Меряем со значком на его месте в группе (за ним — слот: аукцион, маржа).
        if (links.parentElement !== this.element) this.element.insertBefore(links, this.anchor?.parentElement === this.element ? this.anchor : null);
        const rects = title.getClientRects();
        const last = rects[rects.length - 1];
        const wrapped = last && this.element.getBoundingClientRect().top >= last.bottom - 2;
        if (wrapped) title.after(links);
    }
}
