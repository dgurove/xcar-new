// Вспышка вкладки, куда уехала строка (`.is-bumped`): пилюля — сама, вкладка в закрытом меню заголовка (`x-ui.tab-title`,
// 22.10.2026) — само название-кнопка (`[data-bump]` — своя вспышка без раздувания), иначе её никто не увидит.
export function bump(el) {
    let target = el.closest('a, button') ?? el;
    const menu = target.closest('[popover]');
    if (menu && !menu.matches(':popover-open')) {
        const trigger = document.querySelector(`[aria-controls="${CSS.escape(menu.id)}"]`);
        if (trigger) target = trigger;
    }
    target = target.querySelector('[data-bump]') ?? target;
    target.classList.remove('is-bumped');
    void target.offsetWidth;
    target.classList.add('is-bumped');
    target.addEventListener('animationend', () => target.classList.remove('is-bumped'), { once: true });
}
