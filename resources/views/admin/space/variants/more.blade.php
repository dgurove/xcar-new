@if ($stage['total'] > $shown)
    <div class="col-more">ещё {{ $stage['total'] - $shown }}</div>
@endif
