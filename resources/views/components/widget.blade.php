{{--
    NaturalQuery drop-in widget.

    Usage:
        <x-naturalquery::widget />
        <x-naturalquery::widget title="Ask about sales" scheme="orders" :examples="['Top 10 customers by revenue']" />

    All attributes are optional — defaults come from config('naturalquery.widget').
    The widget JS is served by the package route (no publishing needed); to serve a
    published copy instead, run: php artisan vendor:publish --tag=naturalquery-assets
--}}
@php
    $widgetConfig = array_filter([
        'baseUrl'     => rtrim(url(config('naturalquery.routes.prefix', 'naturalquery')), '/'),
        'title'       => $title ?? config('naturalquery.widget.title', 'Ask your data'),
        'placeholder' => $placeholder ?? config('naturalquery.widget.placeholder'),
        'language'    => $language ?? config('naturalquery.widget.language', 'en-IN'),
        'voice'       => $voice ?? config('naturalquery.widget.voice', true),
        'serverVoice' => config('naturalquery.widget.server_voice', true),
        'tts'         => $tts ?? config('naturalquery.widget.tts', true),
        'autoSpeak'   => config('naturalquery.widget.auto_speak', false),
        'conversation'=> $conversation ?? config('naturalquery.widget.conversation', true),
        'examples'    => $examples ?? config('naturalquery.widget.examples', []),
        'themeColor'  => $themeColor ?? config('naturalquery.widget.theme_color', '#2563eb'),
        'footerNote'  => config('naturalquery.widget.footer_note', 'AI-generated · please verify important figures'),
        'scheme'      => $scheme ?? config('naturalquery.default_scheme'),
    ], fn ($v) => $v !== null);
    $widgetId = 'nq-widget-' . \Illuminate\Support\Str::random(6);
@endphp

<div id="{{ $widgetId }}"></div>

<script src="{{ rtrim(url(config('naturalquery.routes.prefix', 'naturalquery')), '/') }}/widget.js" defer></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        NaturalQueryWidget.mount('#{{ $widgetId }}', Object.assign(
            @json($widgetConfig),
            { csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || @json(csrf_token()) }
        ));
    });
</script>
