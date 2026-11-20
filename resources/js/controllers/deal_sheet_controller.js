import { Controller } from '@hotwired/stimulus';

// Сделка поверх чата в XCAR Chat (20.11.2026, `x-chat.deal-sheet`): установленное приложение не открывает другое (на iPhone
// ссылка ушла бы в Safari, где человек даже не вошёл), поэтому сделку показываем шторкой с настоящей страницей CRM или сайта во
// фрейме: вход общий (cookie на `.xcar.ru`), шапку и таб-бар в ней прячет `html.is-embedded` (x-ui.layout). Ловит нажатия по
// ссылкам `a[data-deal-sheet]` на всём экране — и в ленте сообщений, что дорисовывается позже.
export default class extends Controller {
    static targets = ['dialog', 'frame'];

    connect() {
        // Закрыли — фрейм пустеет: открытая страница не слушает живые обновления впустую и не держит память.
        this.onClose = () => this.frameTarget.removeAttribute('src');
        this.dialogTarget.addEventListener('close', this.onClose);
    }

    disconnect() {
        this.dialogTarget.removeEventListener('close', this.onClose);
    }

    click(event) {
        const link = event.target instanceof Element ? event.target.closest('a[data-deal-sheet]') : null;
        if (!link || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        event.preventDefault();
        event.stopPropagation();
        if (this.frameTarget.getAttribute('src') !== link.href) this.frameTarget.src = link.href;
        window.dispatchEvent(new CustomEvent('deal-sheet:open'));
    }
}
