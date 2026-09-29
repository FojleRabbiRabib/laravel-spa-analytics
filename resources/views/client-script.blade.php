@if (config('spa-analytics.enabled'))
<script src="{{ asset('vendor/laravel-spa-analytics/client.js') }}" defer data-spa-analytics data-handshake="{{ route('spa-analytics.identity.handshake') }}" data-identify="{{ route('spa-analytics.identity.identify') }}"@if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}"@endif></script>
@endif
