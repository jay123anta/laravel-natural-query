{{--
    NaturalQuery drop-in widget.

    Usage:
        <x-naturalquery::widget />
        <x-naturalquery::widget title="Ask about sales" dataset="orders" :examples="['Top 10 customers by revenue']" />

    All attributes are optional — defaults come from config('naturalquery.widget').
    The widget JS is served by the package route (no publishing needed); to serve a
    published copy instead, run: php artisan vendor:publish --tag=naturalquery-assets
--}}
@php
    $widgetConfig = array_filter([
        'baseUrl'     => rtrim(url(config('naturalquery.routes.prefix', 'naturalquery')), '/'),
        'title'       => $title ?? config('naturalquery.widget.title', 'Ask your data'),
        'placeholder' => $placeholder ?? config('naturalquery.widget.placeholder'),
        // null lets the widget follow <html lang> and then the browser, rather
        // than imposing one project's locale on everyone's users.
        'language'    => $language ?? config('naturalquery.widget.language'),
        // Taken from the SERVER's setting so the rows the widget formats and
        // the totals the server formats group their digits the same way.
        'numberFormat' => config('naturalquery.response.number_format', 'international'),
        'voice'       => $voice ?? config('naturalquery.widget.voice', true),
        'tts'         => $tts ?? config('naturalquery.widget.tts', true),
        'autoSpeak'   => config('naturalquery.widget.auto_speak', false),
        'conversation'=> $conversation ?? config('naturalquery.widget.conversation', true),
        'examples'    => $examples ?? config('naturalquery.widget.examples', []),
        'themeColor'  => $themeColor ?? config('naturalquery.widget.theme_color', '#2563eb'),
        'footerNote'  => config('naturalquery.widget.footer_note', 'AI-generated · please verify important figures'),
        'dataset'      => $dataset ?? config('naturalquery.default_dataset'),
        // Chat frame height. "auto" means grow with the content — a string
        // rather than null on purpose, because null cannot survive the trip:
        // both `??` here and Blade's own @props treat an explicitly-passed
        // null as "not given" and quietly reinstate the default, so
        // :height="null" would look like it worked and do nothing.
        'height'      => $height ?? config('naturalquery.widget.height', '520px'),
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
