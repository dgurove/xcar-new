import { Controller } from '@hotwired/stimulus';
import { refreshPage } from '../live.js';

// Заявка, которую завела почта (AutoRequest), входит в список живьём (владелец 07.10.2026): силуэт → идёт
// распознавание документов → полная строка. Состояние рисует сервер (AutoScan::states) атрибутом
// data-arrive-state-value: ghost — силуэт, reading — полоса по строке и «Читаем документы, 2 из 5», done или пусто —
// обычная строка. Здесь — только движение:
// - строка, которую вставил морф (пришло письмо, пока список открыт), входит; при обычной загрузке страницы — нет;
// - ghost/reading → done/пусто — строка проявляется и вспыхивает, но не раньше, чем силуэт простоял SILHOUETTE мс
//   (письмо без документов иначе мигнуло бы);
// - по ходу чтения (событие scan своей ТС) страница перечитывается морфом — с тем же сторожем, что live.js.
// Морф переставляет строки, вынимая их и вставляя заново (контроллер отключается и подключается), поэтому прошлое
// строки помнится здесь, по id, а не в контроллере: переставленная не входит второй раз и не теряет проявление.
const SILHOUETTE = 1200;
const ENTER_WINDOW = 1500;
const BUSY = ['ghost', 'reading'];

let morphedAt = -Infinity;
document.addEventListener('turbo:before-render', (e) => { if (e.detail.renderMethod === 'morph') morphedAt = performance.now(); });
// Другая страница (не морф этой) — строки не помним: вернулись в список — он рисуется как есть, без входа.
document.addEventListener('turbo:before-render', (e) => { if (e.detail.renderMethod !== 'morph') seen.clear(); });

/** id строки → { state, since } — что строка показывала и с какого момента на экране. */
const seen = new Map();

export default class extends Controller {
    static values = { state: String, subject: String };

    connect() {
        const id = this.element.id;
        const was = seen.get(id);
        const busy = BUSY.includes(this.stateValue);
        if (!was && busy && performance.now() - morphedAt < ENTER_WINDOW) this.play('is-entering', 700);
        seen.set(id, { state: this.stateValue, since: was?.since ?? performance.now() });
        if (was && BUSY.includes(was.state) && !busy) this.reveal(was.since);
        this.onScan = (e) => {
            if (e.detail?.subject !== this.subjectValue || !BUSY.includes(this.stateValue)) return;
            clearTimeout(this.timer);
            this.timer = setTimeout(refreshPage, 600);
        };
        document.addEventListener('live:scan', this.onScan);
    }

    disconnect() {
        document.removeEventListener('live:scan', this.onScan);
        clearTimeout(this.timer);
        clearTimeout(this.revealTimer);
    }

    // Морф поменял состояние у той же строки (не переставляя её).
    stateValueChanged(state, old) {
        const entry = seen.get(this.element.id);
        // Первый вызов при подключении (old не задан) — прошлое строки разбирает connect.
        if (!entry || old === undefined) return;
        entry.state = state;
        if (BUSY.includes(old) && !BUSY.includes(state)) this.reveal(entry.since);
    }

    // Силуэт не успел постоять — сначала он, потом строка.
    reveal(since) {
        const left = SILHOUETTE - (performance.now() - since);
        this.element.classList.add('is-holding');
        clearTimeout(this.revealTimer);
        this.revealTimer = setTimeout(() => {
            this.element.classList.remove('is-holding');
            this.play('is-revealed', 900);
        }, Math.max(0, left));
    }

    play(name, ms) {
        this.element.classList.remove(name);
        void this.element.offsetWidth;
        this.element.classList.add(name);
        setTimeout(() => this.element.classList.remove(name), ms);
    }
}
