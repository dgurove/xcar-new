import { Controller } from '@hotwired/stimulus';

// «Оценить → Из текста» (x-offer.valuation-sheet): вставленное сообщение уходит на разбор сразу, а этапы идут один за
// другим и показывают, что сделано: лишние строки текста схлопываются, номера и суммы подсвечиваются, находятся
// предложения, ненайденные отходят, оценочные и закупочные бегут счётчиком, в конце рисуется галка. Итог — группы
// строк с галками и «Всё верно, сохранить (N)». Без анимаций (prefers-reduced-motion) — то же самое сразу.
const calm = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const sleep = (ms) => new Promise((r) => setTimeout(r, calm() ? 0 : ms));
const nums = (n) => Math.round(n).toLocaleString('ru-RU').replace(/\s/g, ' ');
const plural = (n, one, few, many) => {
    const m10 = n % 10;
    const m100 = n % 100;
    return m10 === 1 && m100 !== 11 ? one : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? few : many;
};

export default class extends Controller {
    static targets = ['paste', 'text', 'work', 'lines', 'done', 'fail', 'reason', 'result', 'rows', 'token', 'save', 'summary'];
    static values = { url: String };

    pasted() {
        // Текст ещё не в поле: событие paste приходит до вставки.
        setTimeout(() => this.run(), 0);
    }

    async run() {
        const text = this.textTarget.value.trim();
        if (!text || this.busy) return;
        this.busy = true;
        this.pasteTarget.hidden = true;
        this.workTarget.hidden = false;
        this.steps().forEach((s) => this.mark(s.dataset.step, 'wait', ''));
        this.linesTarget.replaceChildren();
        this.linesTarget.hidden = false;
        this.doneTarget.hidden = true;
        this.failTarget.hidden = true;

        this.mark('clean', 'run');
        const started = performance.now();
        let data;
        try {
            const res = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: JSON.stringify({ text }),
            });
            if (!res.ok) throw new Error(String(res.status));
            data = await res.json();
        } catch {
            return this.fail('Не получилось разобрать, попробуйте ещё раз');
        }
        await sleep(Math.max(0, 300 - (performance.now() - started)));

        const { counts } = data;
        // 1. Лишнее уходит, номера и суммы остаются.
        await this.clean(data.lines);
        if (!counts.refs) return this.fail('Номеров убытка в тексте не нашли');
        this.mark('clean', 'done', `${counts.refs} ${plural(counts.refs, 'номер', 'номера', 'номеров')}`);

        // 2. Предложения.
        this.mark('match', 'run');
        await sleep(650);
        this.mark('match', 'done', counts.found ? `нашли ${counts.found}` : 'не нашли');

        // 3. Ненайденные.
        this.mark('drop', 'run');
        await sleep(450);
        this.mark('drop', 'done', counts.missing ? `нет в CRM ${counts.missing}` : 'все нашлись');

        // Строки итога появляются под этапами — дальше считаются прямо в них.
        this.rowsTarget.innerHTML = data.html;
        this.tokenTarget.value = data.token;
        await this.collapseLines();
        this.resultTarget.hidden = false;
        const rows = [...this.rowsTarget.querySelectorAll('[data-valuation-row]')];
        rows.forEach((r) => r.classList.add('is-pending'));
        await this.reveal(rows);

        // 4–5. Оценочные, затем закупочные — счётчиком.
        this.mark('value', 'run');
        await this.countUp(rows, '[data-v]', 'value', false);
        this.mark('value', 'done', counts.take ? `${counts.take} ${plural(counts.take, 'предложение', 'предложения', 'предложений')}` : '');
        this.mark('floor', 'run');
        rows.forEach((r) => r.classList.add('show-floor'));
        await this.countUp(rows, '[data-f]', 'floor', true);
        this.mark('floor', 'done');

        // 6. Готово: галка, потом этапы сворачиваются в строку итога — список получает всю высоту окна.
        this.mark('done', 'done');
        rows.forEach((r) => r.classList.remove('is-pending'));
        this.count();
        await sleep(600);
        this.summaryTarget.textContent = [`${counts.refs} ${plural(counts.refs, 'номер', 'номера', 'номеров')} в тексте`, `нашли ${counts.found}`, counts.missing ? `нет в CRM ${counts.missing}` : null,
            counts.no_vin ? `без VIN ${counts.no_vin}` : null, counts.no_city ? `без города ${counts.no_city}` : null].filter(Boolean).join(', ');
        if (!calm()) await this.workTarget.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 200 }).finished.catch(() => {});
        this.workTarget.hidden = true;
        this.doneTarget.hidden = false;
        this.busy = false;
    }

    // Строки текста: сначала все, потом лишние схлопываются по очереди, а номера и суммы загораются.
    async clean(lines) {
        const els = lines.map((l) => {
            const el = document.createElement('div');
            el.className = 'valuation-line';
            el.textContent = l.text;
            if (l.keep) el.dataset.keep = '';
            this.linesTarget.append(el);
            return el;
        });
        if (calm()) {
            els.filter((el) => !('keep' in el.dataset)).forEach((el) => el.remove());
            els.forEach((el) => el.classList.add('is-kept'));
            return;
        }
        await sleep(250);
        const junk = els.filter((el) => !('keep' in el.dataset));
        const step = Math.max(6, Math.min(40, 1400 / Math.max(1, junk.length)));
        const anims = junk.map((el, i) => el.animate(
            [{ opacity: 1, height: `${el.offsetHeight}px` }, { opacity: 0, height: '0px', paddingBlock: 0 }],
            { duration: 260, delay: i * step, easing: 'ease-in', fill: 'forwards' },
        ));
        els.filter((el) => 'keep' in el.dataset).forEach((el, i) => setTimeout(() => el.classList.add('is-kept'), i * step * 2));
        await Promise.all(anims.map((a) => a.finished.catch(() => {})));
        junk.forEach((el) => el.remove());
    }

    async collapseLines() {
        if (calm()) { this.linesTarget.hidden = true; return; }
        const box = this.linesTarget;
        await box.animate([{ opacity: 1, height: `${box.offsetHeight}px` }, { opacity: 0, height: '0px' }], { duration: 320, easing: 'ease-in-out' }).finished.catch(() => {});
        box.hidden = true;
    }

    async reveal(rows) {
        if (calm()) return;
        const step = Math.max(12, Math.min(60, 900 / Math.max(1, rows.length)));
        await Promise.all(rows.map((r, i) => r.animate(
            [{ opacity: 0, transform: 'translateY(6px)' }, { opacity: 1, transform: 'none' }],
            { duration: 240, delay: i * step, easing: 'ease-out', fill: 'backwards' },
        ).finished.catch(() => {})));
    }

    // Число бежит от нуля до своего значения во всех строках разом.
    async countUp(rows, selector, key, rub) {
        const cells = rows.map((r) => [r.querySelector(selector), Number(r.dataset[key])]).filter(([el, n]) => el && n);
        if (calm()) return;
        const duration = 700;
        const start = performance.now();
        await new Promise((done) => {
            const frame = (now) => {
                const t = Math.min(1, (now - start) / duration);
                const k = 1 - (1 - t) ** 3;
                // Закупочная бежит тысячами — как её и округляют.
                cells.forEach(([el, n]) => { el.textContent = rub ? `${nums(t < 1 ? Math.ceil((n * k) / 1000) * 1000 : n)}\u00A0₽` : nums(n * k); });
                t < 1 ? requestAnimationFrame(frame) : done();
            };
            requestAnimationFrame(frame);
        });
    }

    mark(step, state, count = null) {
        const li = this.element.querySelector(`[data-step="${step}"]`);
        if (!li) return;
        li.dataset.state = state;
        if (count !== null) li.querySelector('[data-count]').textContent = count;
    }

    steps() {
        return [...this.element.querySelectorAll('[data-step]')];
    }

    fail(reason) {
        this.busy = false;
        const running = this.element.querySelector('[data-step][data-state="run"]');
        if (running) running.dataset.state = 'fail';
        this.reasonTarget.textContent = reason;
        this.failTarget.hidden = false;
    }

    overwriteAll(event) {
        event.preventDefault();
        this.rowsTarget.querySelectorAll('input[type=radio][value="overwrite"]').forEach((r) => { r.checked = true; });
        this.count();
    }

    // Сколько предложений изменится: отмеченные и расхождения — оставленное целиком не меняется, если спорит оценочная
    // и нечего дописать (VIN, город).
    count() {
        const n = this.rowsTarget.querySelectorAll('input[type=checkbox][name="offers[]"]:checked').length
            + [...this.rowsTarget.querySelectorAll('.valuation-conflict')].filter((row) => row.querySelector('input[value="overwrite"]').checked
                || !row.querySelector('.valuation-diff dt')?.textContent.includes('Оценочная')
                // Сумму оставили, а VIN или город из текста всё равно лягут.
                || 'extra' in row.dataset).length;
        this.saveTarget.disabled = n === 0;
        this.saveTarget.textContent = n ? `Всё верно, сохранить (${n})` : 'Нечего сохранять';
    }

    reset() {
        this.busy = false;
        this.textTarget.value = '';
        this.pasteTarget.hidden = false;
        this.workTarget.hidden = true;
        this.resultTarget.hidden = true;
        this.rowsTarget.replaceChildren();
    }
}
