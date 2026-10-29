import { Controller } from '@hotwired/stimulus';

// Таблица не влезает в ширину — листается вбок внутри своей плашки (владелец 04.10.2026: «это же логично»). Только
// когда не влезает: у прокручиваемой плашки липкие заголовки групп («Наличие», «Заявки») перестают липнуть.
//
// Строк контроллер не меряет: ширины столбцов таблицы сеткой считает сервер по самим значениям (`Support\Table\Widths`,
// 30.10.2026 — до того каждая строка, подходящая к экрану, мерилась прямо во время прокрутки).
export default class extends Controller {
    connect() {
        this.box = this.element.querySelector('.table-box');
        this.table = this.box?.querySelector('table');
        if (!this.table) return;
        this.observer = new ResizeObserver(() => this.sync());
        this.observer.observe(this.box);
        this.observer.observe(this.table);
        this.sync();
    }

    disconnect() {
        this.observer?.disconnect();
    }

    sync() {
        this.box.classList.toggle('is-scroll', this.table.offsetWidth > this.box.clientWidth + 1);
    }
}
