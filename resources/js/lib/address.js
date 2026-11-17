// Адрес страницы без визита Turbo — одна дверь (17.11.2026). Открытое окно строки (`?peek=`) и догруженные строки (`rows=`)
// меняют адрес, не перерисовывая страницу: запись истории та же, ключ восстановления тот же. Раньше это делали три копии
// кода, и снимок страницы Turbo ложился под адресом последней отрисовки, а не под нынешним: «Назад» на запись с `rows=`
// или `peek=` промахивался мимо снимка и шёл на сервер. Здесь вместе с адресом ставится и ключ снимка, а память разделов
// (`lib/sections.js`) узнаёт новое место событием `address:change`.
import * as Turbo from '@hotwired/turbo';

export function setAddress(url) {
    const to = new URL(url, location.href);
    const history_ = Turbo.session?.history;
    if (history_) {
        history_.replace(to, history_.restorationIdentifier);
        // Ключ снимка — только в пределах той же страницы: у другого пути своя отрисовка и свой морф (`isPageRefresh`).
        const view = Turbo.session.view;
        if (view?.lastRenderedLocation?.pathname === to.pathname) view.lastRenderedLocation = to;
    } else {
        history.replaceState(history.state, '', to);
    }
    document.dispatchEvent(new CustomEvent('address:change', { detail: { url: to.href } }));
}
