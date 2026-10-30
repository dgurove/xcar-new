// Вспышка вкладки, куда уехала строка (`.is-bumped`): пилюля — сама, вкладка в закрытом колесе заголовка (`x-ui.tab-title`,
// 22.10.2026) — само название-кнопка (`[data-bump]` — своя вспышка без раздувания), иначе её никто не увидит.
export function bump(el) {
    let target = el.closest('a, button') ?? el;
    // Меню-popover закрыто или колесо вкладок (`data-wheel`) не открыто — вспыхивает то, что их открывает.
    const menu = target.closest('[popover], [data-wheel]');
    if (menu && (menu.hasAttribute('popover') ? !menu.matches(':popover-open') : menu.hidden)) {
        const trigger = document.querySelector(`[aria-controls="${CSS.escape(menu.id)}"]`);
        if (trigger) target = trigger;
    }
    target = target.querySelector('[data-bump]') ?? target;
    target.classList.remove('is-bumped');
    void target.offsetWidth;
    target.classList.add('is-bumped');
    target.addEventListener('animationend', () => target.classList.remove('is-bumped'), { once: true });
}
