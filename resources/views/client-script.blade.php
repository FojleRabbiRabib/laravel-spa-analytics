@if ($scriptUrl !== '')
<script src="{{ $scriptUrl }}" defer data-spa-analytics data-handshake="{{ route('spa-analytics.identity.handshake') }}" data-identify="{{ route('spa-analytics.identity.identify') }}" data-collect="{{ route('spa-analytics.collect') }}" data-downloads="{{ implode(',', (array) config('spa-analytics.collect.download_extensions')) }}"@if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}"@endif></script>
@endif
