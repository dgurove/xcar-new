import { Controller } from '@hotwired/stimulus';

// Сделка поверх чата в XCAR Chat и поверх почты в XCAR Mail (20.11.2026, `x-ui.deal-sheet`): установленное приложение не открывает другое (на iPhone
// ссылка ушла бы в Safari, где человек даже не вошёл), поэтому сделку показываем шторкой с настоящей страницей CRM или сайта во
// фрейме: вход общий (cookie на `.xcar.ru`), шапку и таб-бар в ней прячет `html.is-embedded` (x-ui.layout). Ловит нажатия по
// ссылкам `a[data-deal-sheet]` на всём экране — и в ленте сообщений, что дорисовывается позже.
export default class extends Controller {
    static targets = ['dialog', 'frame'];
    static values = { open: String };

    connect() {
        // Закрыли — фрейм пустеет: открытая страница не слушает живые обновления впустую и не держит память.
        this.onClose = () => this.frameTarget.removeAttribute('src');
        this.dialogTarget.addEventListener('close', this.onClose);
    }

    // Сервер просит открыть сразу (`deal-sheet` во flash): и при загрузке, и при морфе той же страницы (Turbo после редиректа
    // на тот же адрес не переподключает контроллер, а меняет атрибут). Шторка внутри ещё подключается — следующим кадром.
    // Атрибут снимаем: страница из кэша Turbo («Назад») шторку второй раз не откроет.
    openValueChanged(href) {
        if (!href) return;
        this.element.removeAttribute('data-deal-sheet-open-value');
        requestAnimationFrame(() => this.show(href));
    }

    disconnect() {
        this.dialogTarget.removeEventListener('close', this.onClose);
    }

    click(event) {
        const link = event.target instanceof Element ? event.target.closest('a[data-deal-sheet]') : null;
        if (!link || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        event.stopPropagation();
        this.show(link.href);
    }

    show(href) {
        if (this.frameTarget.getAttribute('src') !== href) this.frameTarget.src = href;
        window.dispatchEvent(new CustomEvent('deal-sheet:open'));
    }
}
