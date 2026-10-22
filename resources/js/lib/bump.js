// Вспышка вкладки, куда уехала строка (`.is-bumped`): пилюля — сама, вкладка в закрытом меню заголовка (`x-ui.tab-title`,
// 22.10.2026) — кружок со стрелкой у её кнопки (`[data-bump]`), иначе вспышку никто не увидит; число у самого
// заголовка вспыхивает тем же кружком.
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
