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
// С 12.10.2026 так же черновик предложения из письма (AutoOffer, предмет o:): files — едут фото, «Забираем фото, 3 из
// 23»; вход — закупочная отсчитывается от нуля, номер убытка печатается; взлетевшей с места призрака строке (flip.js,
// «Из таблицы Кати») вход сверху снимает flip.js — она загорается волной. Ход фото присылает сервер событием refresh
// (`AutoScan::photoArrived`), чтения — событием scan; опроса нет. Силуэта при проявлении у черновика нет: его номер,
// машина и закупочная стоят с первой секунды, проявляется только то, что пришло потом (`.arrive-late`).
const SILHOUETTE = 1200;
const ENTER_WINDOW = 1500;
const BUSY = ['ghost', 'files', 'reading'];

// Событие scan приходит за каждым файлом каждой строки: одно перечитывание на всех, а не по таймеру на строку.
let scanTimer = null;
function refreshSoon() {
    clearTimeout(scanTimer);
    scanTimer = setTimeout(refreshPage, 600);
}

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
        if (!was && busy && performance.now() - morphedAt < ENTER_WINDOW) {
            this.play('is-entering', 1800);
            this.countUp();
        }
        seen.set(id, { state: this.stateValue, since: was?.since ?? performance.now() });
        if (was && BUSY.includes(was.state) && !busy) this.reveal(was.since);
        this.onScan = (e) => {
            if (e.detail?.subject === this.subjectValue && BUSY.includes(this.stateValue)) refreshSoon();
        };
        document.addEventListener('live:scan', this.onScan);
    }

    disconnect() {
        document.removeEventListener('live:scan', this.onScan);
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
        if (this.subjectValue.startsWith('o:')) return this.play('is-revealed', 1400);
        const left = SILHOUETTE - (performance.now() - since);
        this.element.classList.add('is-holding');
        clearTimeout(this.revealTimer);
        this.revealTimer = setTimeout(() => {
            this.element.classList.remove('is-holding');
            this.play('is-revealed', 900);
        }, Math.max(0, left));
    }

    // Закупочная из письма — отсчётом от нуля: видно, почему строка встала в «Без продажной цены».
    countUp() {
        for (const el of this.element.querySelectorAll('[data-arrive-count]')) {
            const text = el.textContent;
            const to = Number(text.replace(/\D/g, '')) || 0;
            const space = text.match(/\d(\D)\d/)?.[1] ?? '\u00a0';
            if (!to || matchMedia('(prefers-reduced-motion: reduce)').matches) continue;
            const start = performance.now();
            const frame = (now) => {
                const t = Math.min(1, (now - start) / 1100);
                const value = Math.round(to * (1 - (1 - t) ** 3));
                el.textContent = t < 1 ? String(value).replace(/\B(?=(\d{3})+(?!\d))/g, space) : text;
                if (t < 1) requestAnimationFrame(frame);
            };
            el.classList.add('arrive-counting');
            setTimeout(() => el.classList.remove('arrive-counting'), 1800);
            requestAnimationFrame(frame);
        }
    }

    play(name, ms) {
        this.element.classList.remove(name);
        void this.element.offsetWidth;
        this.element.classList.add(name);
        setTimeout(() => this.element.classList.remove(name), ms);
    }
}
