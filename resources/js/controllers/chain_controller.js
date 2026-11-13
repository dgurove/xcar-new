import { Controller } from '@hotwired/stimulus';

// Лента писем: при загрузке прокручивается к письму в фокусе (focus). Письма раскрываются гармошкой (13.11.2026, начальник:
// с телефона письма по нажатию не раскрывались — «раскрывалось одно, остальные сворачивались»): нажатие по шапке письма
// ведёт сам контроллер, а не родной <details> — раскрытое плавно растёт, прежнее раскрытое того же уровня плавно
// сворачивается, раскрытое встаёт к верху окна. many — разбор письма: открытых бывает несколько, соседи не сворачиваются.
export default class extends Controller {
    static targets = ['focus'];
    static values = { many: Boolean };

    connect() {
        this.onClick = (e) => this.click(e);
        this.element.addEventListener('click', this.onClick);
        if (!this.hasFocusTarget || this.focusTarget === this.element.querySelector('.letter')) return;
        requestAnimationFrame(() => this.reveal(this.focusTarget));
    }

    disconnect() {
        this.element.removeEventListener('click', this.onClick);
    }

    // Раскрывает и шапка письма, и всё вокруг неё в узле — кружок отправителя, точка, линия слева, промежуток до текста
    // (13.11.2026, начальник снова: «ничего не происходит» — жал, похоже, по кружкам на линии). Внутри раскрытого письма
    // нажатия свои (фото, файлы, «Исходное письмо»).
    click(e) {
        if (e.target.closest('a, button, input, label, textarea, select')) return;
        const head = e.target.closest('summary');
        let details = head?.parentElement;
        if (head && !head.matches('.letter-head')) return;
        if (!head) {
            const node = e.target.closest('.letter');
            details = node?.querySelector(':scope > details.letter-body');
            if (!details || details.contains(e.target)) return;
        }
        if (!details?.matches('details.letter-body') || !this.element.contains(details)) return;
        e.preventDefault();
        if (details.dataset.moving) return;
        if (details.open) { this.fold(details); return; }
        if (!this.manyValue) {
            // Соседи того же уровня (служебные внутри узла «3 служебных» — свой уровень).
            const level = details.closest('.letter')?.parentElement;
            for (const other of level?.querySelectorAll(':scope > .letter > details.letter-body[open]') ?? []) {
                if (other !== details) this.fold(other);
            }
        }
        this.unfold(details);
    }

    get calm() {
        return matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    // Высота от шапки до полной: open сразу (содержимое уже в потоке), высота догоняет.
    unfold(details) {
        const from = details.offsetHeight;
        details.open = true;
        if (this.calm) { this.settled(details); return; }
        const to = details.offsetHeight;
        this.animate(details, from, to, () => this.settled(details));
    }

    fold(details) {
        if (this.calm) { details.open = false; return; }
        const from = details.offsetHeight;
        const head = details.querySelector(':scope > summary');
        const pad = parseFloat(getComputedStyle(details).paddingBottom) || 0;
        const to = head.offsetHeight + pad;
        this.animate(details, from, to, () => { details.open = false; });
    }

    animate(details, from, to, done) {
        details.dataset.moving = '';
        details.style.overflow = 'hidden';
        const ease = getComputedStyle(document.documentElement).getPropertyValue('--ease-sheet').trim() || 'ease-out';
        const run = details.animate({ height: [`${from}px`, `${to}px`] }, { duration: 280, easing: ease });
        run.onfinish = run.oncancel = () => {
            details.style.overflow = '';
            delete details.dataset.moving;
            done();
        };
    }

    // Раскрытое — шапкой к верху окна, если ушло из виду (выше свернулись соседи — оно поднялось само).
    settled(details) {
        const box = this.scroller(details);
        const top = this.top(box);
        const r = details.getBoundingClientRect();
        if (r.top >= top && r.top < (box ? box.getBoundingClientRect().bottom : innerHeight) - 120) return;
        if (box) box.scrollTo({ top: box.scrollTop + r.top - top, behavior: this.calm ? 'auto' : 'smooth' });
        else details.scrollIntoView({ block: 'start', behavior: this.calm ? 'auto' : 'smooth' });
    }

    scroller(el) {
        for (let box = el.parentElement; box && box !== document.body; box = box.parentElement) {
            if (box.scrollHeight > box.clientHeight + 1 && /(auto|scroll)/.test(getComputedStyle(box).overflowY)) return box;
        }
        return null;
    }

    top(box) {
        if (!box) return 8;
        const bar = box.querySelector(':scope > .sheet-bar');
        return (bar ? bar.getBoundingClientRect().bottom : box.getBoundingClientRect().top) + 8;
    }

    // Прокрутка своего окна (лента лежит в шторке писем), не scrollIntoView: тот листал и страницу под шторкой — после
    // закрытия писем человек стоял в другом месте списка.
    reveal(el) {
        const box = this.scroller(el);
        if (!box) { el.scrollIntoView({ block: 'start' }); return; }
        box.scrollTop += el.getBoundingClientRect().top - this.top(box);
    }
}
