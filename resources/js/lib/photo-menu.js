import { openSheet, closeSheet, outside } from '../sheet';
import { ICONS } from '../lightbox';

// Меню кадра долгим нажатием или правой кнопкой (23.11.2026, владелец выбрал из макетов «как в „Фото“ iOS»): на телефоне —
// шторка действий, как «⋯» (`x-ui.more`: пункты одной карточкой, опасное последним, «Отмена»), на ПК с мышью — поповер у
// курсора. items: [{ label, icon, run, danger? }]. Пункты зовут то же, что кнопки кадра (photos_controller); onClose — меню
// закрылось (с выбором или без), до действия пункта.
const POP = matchMedia('(min-width: 1024px) and (pointer: fine)');
const svg = (d) => `<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="${d}"/></svg>`;

export function photoMenu(items, at, onClose) {
    document.getElementById('photo-menu')?.remove();
    const d = document.createElement('dialog');
    d.id = 'photo-menu';
    d.className = 'sheet sheet-actions photo-menu';
    d.dataset.turboTemporary = '';
    const list = document.createElement('div');
    list.className = 'sheet-actions-list';
    let chosen = null;
    for (const it of items) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'menu-item' + (it.danger ? ' text-danger' : '');
        b.setAttribute('role', 'menuitem');
        b.innerHTML = `<span class="${it.danger ? '' : 'text-ink-muted'}">${svg(ICONS[it.icon] ?? it.icon)}</span>`;
        b.append(it.label);
        b.addEventListener('click', () => { chosen = it.run; close(); });
        list.append(b);
    }
    const cancel = Object.assign(document.createElement('button'), { type: 'button', className: 'sheet-actions-cancel', textContent: 'Отмена' });
    cancel.addEventListener('click', () => close());
    d.append(list, cancel);
    document.body.append(d);

    const popped = POP.matches && 'showPopover' in d;
    // Действие — после закрытия: подтверждение удаления — своя шторка, поверх закрывающейся она бы не встала.
    const done = () => { d.remove(); const run = chosen; chosen = null; onClose?.(); run?.(); };
    function close() {
        if (popped) { d.hidePopover(); return; }
        closeSheet(d);
    }
    if (popped) {
        d.setAttribute('popover', 'auto');
        // У курсора, не за краем окна: поповер шириной 17rem, высота — по пунктам.
        d.style.setProperty('--pop-left', `${Math.min(at.x, innerWidth - 17 * 16 - 8)}px`);
        d.style.setProperty('--pop-top', `${Math.min(at.y, innerHeight - items.length * 44 - 16)}px`);
        d.addEventListener('toggle', (e) => { if (e.newState === 'closed') done(); });
        d.showPopover();
        return;
    }
    // Палец ещё на экране: клик от его отпускания приходит уже в шторку — не закрывать.
    const openedAt = Date.now();
    d.addEventListener('click', (e) => { if (outside(d, e) && Date.now() - openedAt > 600) close(); });
    d.addEventListener('close', done);
    openSheet(d, { history: false });
}
