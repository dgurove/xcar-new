import { Controller } from '@hotwired/stimulus';
import { openSheet, closeSheet } from '../sheet';

// Поповер вместо шторки (x-ui.sheet anchor) — только на ПК с мышью.
const POP = matchMedia('(min-width: 1024px) and (pointer: fine)');
// Открыт поповером. Без атрибута popover селектор не спрашиваем: где поповеров нет, :popover-open бросает.
const popped = (d) => d.hasAttribute('popover') && d.matches(':popover-open');

// Шторка на <dialog>. Открывается кнопкой в том же контроллере, событием
// (например deal:open@window) или сразу, если сервер вернул форму с ошибками
// (data-sheet-open-value). inflow — медиазапрос, при котором диалог стоит в
// потоке карточкой, а не шторкой (блок сделки на широком экране).
// anchor (data-sheet-anchor у диалога) — на ПК с мышью тот же диалог поповером у нажатой кнопки (чипы фильтра):
// popover="auto" в top layer, без затемнения; клик мимо и Esc закрывают его сами, записи в истории нет.
export default class extends Controller {
    static targets = ['dialog'];
    static values = { inflow: String };

    connect() {
        if (this.hasInflowValue && this.inflowValue) {
            this.media = matchMedia(this.inflowValue);
            this.onMedia = () => this.place();
            this.media.addEventListener('change', this.onMedia);
            this.place();
        }
        if (this.dialogTarget.dataset.sheetOpenValue === 'true') this.open();
        // POST → redirect на ту же страницу Turbo делает морфом: контроллер не переподключается, а сервер
        // просит открыть шторку (выданная ссылка, ошибки формы) — открываем после морфа.
        this.onMorph = () => {
            if (this.hasDialogTarget && this.dialogTarget.dataset.sheetOpenValue === 'true' && !this.dialogTarget.open) this.open();
        };
        document.addEventListener('turbo:morph', this.onMorph);
    }

    disconnect() {
        this.media?.removeEventListener('change', this.onMedia);
        document.removeEventListener('turbo:morph', this.onMorph);
        this.unpop();
        // Turbo сносит поддерево целиком — диалога может уже не быть, и обращение к цели роняло консоль.
        if (this.hasDialogTarget && this.dialogTarget.open) this.dialogTarget.close();
    }


    // В потоке — открыт немодально; иначе закрыт до вызова open().
    place() {
        const d = this.dialogTarget;
        if (this.media.matches) {
            if (d.open) d.close();
            // show() ставит фокус на первую кнопку диалога: в потоке это кольцо на «полной цене» сразу после загрузки.
            const was = document.activeElement;
            d.show();
            if (d.contains(document.activeElement) && !d.contains(was)) document.activeElement.blur();
        } else if (d.open && !d.matches(':modal')) {
            d.close();
        }
    }

    // Клавиатура выезжает только там, где поле помечено autofocus (поиск);
    // фильтры и формы открываются целиком.
    open(event) {
        if (this.media?.matches) return;
        if ('sheetAnchor' in this.dialogTarget.dataset && POP.matches && 'showPopover' in this.dialogTarget) { this.pop(event); return; }
        openSheet(this.dialogTarget);
        this.dialogTarget.querySelector('[autofocus]')?.focus();
    }

    close() {
        if (this.media?.matches) { this.dialogTarget.close(); this.dialogTarget.show(); return; }
        if (popped(this.dialogTarget)) { this.dialogTarget.hidePopover(); return; }
        closeSheet(this.dialogTarget);
    }

    backdrop(event) {
        if (event.target === this.dialogTarget && this.dialogTarget.matches(':modal')) this.close();
    }

    // Поповер у нажатой кнопки (её капсулы — у чипа с «×» кнопка внутри). Повторное нажатие закрывает. Где
    // showPopover({ source }) не поддержан, клик мимо закрывает поповер раньше, чем дойдёт click по той же кнопке, —
    // только что закрытый по ней не открывается снова.
    pop(event) {
        const d = this.dialogTarget;
        if (popped(d)) { d.hidePopover(); return; }
        if (performance.now() - (this.dismissed ?? -Infinity) < 300) return;
        const trigger = event?.currentTarget instanceof Element ? event.currentTarget : null;
        this.anchor = trigger?.closest('.pill, .btn') ?? trigger;
        if (d.open) d.close();
        if (!this.onToggle) {
            this.onToggle = (e) => {
                if (e.newState === 'closed') { this.dismissed = performance.now(); this.unlisten(); }
            };
            // Закрыт — снова обычный диалог: шторкой он откроется, если окно сузят.
            this.onToggled = (e) => { if (e.newState === 'closed' && !popped(d)) d.removeAttribute('popover'); };
            this.onPlace = (e) => { if (!(e?.target instanceof Node && d.contains(e.target))) this.placePop(); };
            this.onNarrow = () => { if (!POP.matches && popped(d)) d.hidePopover(); };
            d.addEventListener('beforetoggle', this.onToggle);
            d.addEventListener('toggle', this.onToggled);
        }
        d.popover = 'auto';
        d.showPopover({ source: trigger ?? undefined });
        this.placePop();
        addEventListener('scroll', this.onPlace, { capture: true, passive: true });
        addEventListener('resize', this.onPlace);
        POP.addEventListener('change', this.onNarrow);
        // С клавиатуры фокус — в поповер (поиск по вариантам или первая галка), мышью — остаётся на чипе.
        if (event?.detail === 0) d.querySelector('input:not([type="hidden"]), button:not(.sheet-close)')?.focus();
    }

    // Под кнопкой от её левого края, у правого края экрана — сдвинут влево; снизу мало места — над кнопкой.
    // Место и высоту получает CSS переменными (.sheet:popover-open): шторкой диалог их не видит.
    placePop() {
        const d = this.dialogTarget;
        if (!this.anchor?.isConnected) return;
        const r = this.anchor.getBoundingClientRect(), w = document.documentElement.clientWidth, h = innerHeight, gap = 8, edge = 8;
        const below = h - r.bottom - gap - edge, above = r.top - gap - edge, up = below < 320 && above > below;
        d.style.setProperty('--pop-top', up ? 'auto' : `${r.bottom + gap}px`);
        d.style.setProperty('--pop-bottom', up ? `${h - r.top + gap}px` : 'auto');
        d.style.setProperty('--pop-max', `${Math.max(160, up ? above : below)}px`);
        d.style.setProperty('--pop-left', `${Math.max(edge, Math.min(r.left, w - d.offsetWidth - edge))}px`);
    }

    unlisten() {
        if (!this.onPlace) return;
        removeEventListener('scroll', this.onPlace, { capture: true });
        removeEventListener('resize', this.onPlace);
        POP.removeEventListener('change', this.onNarrow);
    }

    unpop() {
        this.unlisten();
        if (this.hasDialogTarget && popped(this.dialogTarget)) this.dialogTarget.hidePopover();
    }
}
