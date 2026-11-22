import { Controller } from '@hotwired/stimulus';

// Сделка поверх чата в XCAR Chat и поверх почты в XCAR Mail (20.11.2026, `x-ui.deal-sheet`): установленное приложение не открывает другое (на iPhone
// ссылка ушла бы в Safari, где человек даже не вошёл), поэтому сделку показываем шторкой с настоящей страницей CRM или сайта во
// фрейме: вход общий (cookie на `.xcar.ru`), шапку и таб-бар в ней прячет `html.is-embedded` (x-ui.layout). Ловит нажатия по
// ссылкам `a[data-deal-sheet]` на всём экране — и в ленте сообщений, что дорисовывается позже.
export default class extends Controller {
    static targets = ['dialog', 'frame'];
    static values = { open: String };

    connect() {
        // Закрыли — фрейм заменяется чистым: открытая страница не слушает живые обновления впустую и не держит память, а её
        // переходы уходят из истории вкладки. Иначе «Назад» (и свайп) после шторки листал страницы фрейма, а не вёл из чата к
        // списку (21.11.2026, «чаты поломались»).
        this.onClose = () => this.fresh();
        this.dialogTarget.addEventListener('close', this.onClose);
    }

    // Сервер просит открыть сразу (`deal-sheet` во flash): и при загрузке, и при морфе той же страницы (Turbo после редиректа
    // на тот же адрес не переподключает контроллер, а меняет атрибут). Шторка внутри ещё подключается — следующим кадром.
    // Атрибут снимаем: страница из кэша Turbo («Назад») шторку второй раз не откроет.
    openValueChanged(href) {
        if (!href) return;
        this.element.removeAttribute('data-deal-sheet-open-value');
        // `?deal=` / `?car=` из ссылки уведомления (XCAR Pay) — разовые: обновление страницы шторку второй раз не откроет.
        const url = new URL(location.href);
        if (url.searchParams.has('deal') || url.searchParams.has('car')) {
            url.searchParams.delete('deal');
            url.searchParams.delete('car');
            history.replaceState(history.state, '', url);
        }
        requestAnimationFrame(() => this.show(href));
    }

    disconnect() {
        this.dialogTarget.removeEventListener('close', this.onClose);
    }

    click(event) {
        let link = event.target instanceof Element ? event.target.closest('a[data-deal-sheet]') : null;
        // Строка таблицы со шторкой (XCAR Pay, `x-ui.row-link sheet`): нажатие мимо кнопок и ссылок строки — её ссылка.
        if (!link && event.target instanceof Element && !event.target.closest('a, button, input, select, textarea, label, form, dialog')) {
            link = event.target.closest('[data-deal-sheet-row]')?.querySelector('a[data-deal-sheet]') ?? null;
        }
        if (!link || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        event.stopPropagation();
        this.show(link.href);
    }

    // Каждая сделка — во фрейме, вставленном заново: первая загрузка нового фрейма заменяет его пустую страницу и в историю
    // вкладки не пишется, а смена `src` у загруженного фрейма добавляла запись.
    show(href) {
        if (this.frameTarget.getAttribute('src') !== href) this.fresh(href);
        window.dispatchEvent(new CustomEvent('deal-sheet:open'));
    }

    fresh(href = null) {
        const frame = this.frameTarget.cloneNode(false);
        frame.removeAttribute('src');
        if (href) frame.src = href;
        this.frameTarget.replaceWith(frame);
    }
}
