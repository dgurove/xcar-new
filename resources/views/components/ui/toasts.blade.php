<div class="toasts" data-controller="toast" id="toasts"
     @if (session('toast')) data-toast-message-value="{{ session('toast') }}" @endif
     @if (session('toast-danger')) data-toast-message-value="{{ session('toast-danger') }}" data-toast-kind-value="danger" @endif></div>
