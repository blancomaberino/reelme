{{--
  Shell for the public legal documents (T-054).

  Visual language is inherited from `list-share.blade.php` — the same CSS custom
  properties, the same brand mark, the same dark-mode block — because those two
  pages are the entire public web surface of this product and a legal page that
  looked like a different company is exactly the kind of thing that reads as a
  phishing page.

  Two deliberate departures, both about reading rather than branding: the prose
  is set in a serif at a 68ch measure (a page nobody can read is a page nobody
  can consent to), and there are no webfonts at all. A privacy policy that opens
  a connection to a font CDN in order to render is self-refuting, and it would
  also be the only third-party request on the page.
--}}
@php($otherDoc = $doc === 'privacy' ? 'terms' : 'privacy')
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reelmap · @yield('title')</title>
  <meta name="description" content="@yield('summary')">
  <meta name="color-scheme" content="light dark">
  <link rel="canonical" href="{{ url("/{$doc}/{$locale}") }}">
  {{-- One alternate per OTHER locale, derived from the list rather than a
       hardcoded pair: with a third language the pair would silently advertise
       only one of them. --}}
  @foreach ($locales as $code)
    @if ($code !== $locale)
      <link rel="alternate" hreflang="{{ $code }}" href="{{ url("/{$doc}/{$code}") }}">
    @endif
  @endforeach
  <style>
    :root {
      --bg: #f6f7f9; --card: #ffffff; --ink: #14181f; --muted: #66707d;
      --line: #e7eaef; --primary: #208aef; --on-primary: #ffffff;
      --mark: rgba(32, 138, 239, .10);
    }
    @media (prefers-color-scheme: dark) {
      :root { --bg: #0d1013; --card: #161a1f; --ink: #eef1f5; --muted: #98a2b0; --line: #262c34;
        --mark: rgba(32, 138, 239, .18); }
    }
    * { box-sizing: border-box; }
    html { -webkit-text-size-adjust: 100%; scroll-behavior: smooth; }
    @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } }
    body { margin: 0; background: var(--bg); color: var(--ink);
      font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }

    /* Slim sticky bar: in a document this long, the way back and the language
       switch must not be scrolled away from. */
    .bar { position: sticky; top: 0; z-index: 2; background: color-mix(in srgb, var(--bg) 88%, transparent);
      -webkit-backdrop-filter: saturate(180%) blur(12px); backdrop-filter: saturate(180%) blur(12px);
      border-bottom: 1px solid var(--line); }
    .bar-in { max-width: 760px; margin: 0 auto; padding: 12px 20px;
      display: flex; align-items: center; gap: 12px; }
    .brand { display: flex; align-items: center; gap: 8px; font-weight: 800; color: var(--primary);
      letter-spacing: -.02em; text-decoration: none; }
    .brand .dot { width: 12px; height: 12px; border-radius: 50%; background: var(--primary); }
    .spacer { flex: 1; }

    /* Language switch. A link pair, not a <select>: it works with JS off, each
       state is its own crawlable URL, and the current one is not a link. */
    .langs { display: inline-flex; border: 1px solid var(--line); border-radius: 999px;
      overflow: hidden; background: var(--card); }
    .langs a, .langs span { padding: 5px 12px; font-size: 13px; font-weight: 700; text-decoration: none; }
    .langs a { color: var(--muted); }
    .langs a:hover { color: var(--ink); }
    .langs span[aria-current] { background: var(--primary); color: var(--on-primary); }

    .wrap { max-width: 760px; margin: 0 auto; padding: 32px 20px 80px; }

    h1 { font-size: clamp(28px, 6vw, 38px); font-weight: 800; letter-spacing: -.03em;
      line-height: 1.1; margin: 8px 0 10px; }
    .lead { font-size: 17px; color: var(--muted); margin: 0 0 20px; max-width: 62ch; }
    .stamp { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted);
      background: var(--card); border: 1px solid var(--line); border-radius: 999px; padding: 6px 14px; }

    /* Contents. Legal text is reference material — people arrive looking for one
       clause (usually deletion), and hunting for it by scrolling is the reason
       policies go unread. */
    nav.toc { background: var(--card); border: 1px solid var(--line); border-radius: 14px;
      padding: 16px 18px; margin: 26px 0 8px; }
    nav.toc h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: var(--muted);
      margin: 0 0 10px; font-weight: 700; }
    nav.toc ol { margin: 0; padding: 0; list-style: none; counter-reset: toc;
      display: grid; gap: 7px; }
    nav.toc li { counter-increment: toc; }
    nav.toc a { color: var(--ink); text-decoration: none; font-size: 14px; }
    /* min-width fits a two-digit number: both these documents have more than
       nine sections, and at 1.7em the "10." ran into its own label. */
    nav.toc a::before { content: counter(toc) "."; color: var(--muted); font-variant-numeric: tabular-nums;
      display: inline-block; min-width: 2.2em; }
    nav.toc a:hover { color: var(--primary); text-decoration: underline; }

    /* The prose itself. Serif, 68ch, 1.7 — the document voice, distinct from
       the product chrome around it. */
    .prose { font-family: ui-serif, Georgia, "Iowan Old Style", "Times New Roman", serif;
      font-size: 17px; line-height: 1.7; max-width: 68ch; }
    .prose h2 { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      font-size: 21px; font-weight: 800; letter-spacing: -.02em; line-height: 1.25;
      margin: 40px 0 12px; scroll-margin-top: 70px; }
    .prose h3 { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      font-size: 16px; font-weight: 700; margin: 26px 0 6px; }
    .prose p { margin: 0 0 14px; }
    .prose ul { margin: 0 0 16px; padding-left: 22px; }
    .prose li { margin-bottom: 8px; }
    .prose a { color: var(--primary); }
    .prose strong { font-weight: 700; }
    /* Section numbers come from the heading counter, so the prose and the table
       of contents cannot disagree about what section 7 is. */
    .prose { counter-reset: sec; }
    .prose h2 { counter-increment: sec; }
    .prose h2::before { content: counter(sec) ". "; color: var(--muted); font-variant-numeric: tabular-nums; }

    /* A callout for the handful of statements that are the actual answer
       someone came for (deletion, zero tolerance, what leaves our servers). */
    .note { background: var(--mark); border-left: 3px solid var(--primary); border-radius: 0 10px 10px 0;
      padding: 12px 16px; margin: 0 0 16px; }
    .note p:last-child { margin-bottom: 0; }

    /* The horizontal scroll lives on a WRAPPER, not on the table.
       `display: block` on a <table> drops row/column semantics in several
       browser + screen-reader combinations, and this table is the one place in
       the policy that encodes a relationship rather than a sentence — purpose ↔
       data ↔ legal basis. Losing that leaves a blind reader with nine
       unattached phrases. The wrapper is focusable so the scroll area can be
       reached by keyboard at all. */
    .table-wrap { overflow-x: auto; margin: 0 0 18px; }
    .table-wrap:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    th, td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
    th { font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }

    footer { margin-top: 48px; padding-top: 22px; border-top: 1px solid var(--line); font-size: 14px;
      color: var(--muted); display: flex; flex-wrap: wrap; gap: 10px 20px; align-items: center; }
    footer a { color: var(--primary); }
    .contact { display: inline-block; margin-top: 6px; }

    a:focus-visible, .langs a:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; border-radius: 4px; }

    /* People print these, and a signed-off copy of what the terms said on the
       day matters later. Drop the chrome, keep the words. */
    @media print {
      .bar, nav.toc { display: none; }
      body { background: #fff; color: #000; }
      .prose { max-width: none; font-size: 11pt; }
      .prose a::after { content: " (" attr(href) ")"; font-size: 9pt; color: #555; }
    }
  </style>
</head>
<body>
  <div class="bar">
    <div class="bar-in">
      <a class="brand" href="{{ url('/') }}"><span class="dot"></span> Reelmap</a>
      <span class="spacer"></span>
      <div class="langs">
        @foreach ($locales as $code)
          @if ($code === $locale)
            <span aria-current="page">{{ strtoupper($code) }}</span>
          @else
            <a href="{{ url("/{$doc}/{$code}") }}" hreflang="{{ $code }}">{{ strtoupper($code) }}</a>
          @endif
        @endforeach
      </div>
    </div>
  </div>

  <div class="wrap">
    <h1>@yield('title')</h1>
    <p class="lead">@yield('summary')</p>
    <span class="stamp">@yield('updated-label') <time datetime="{{ $updatedIso }}">{{ $updated }}</time></span>

    <nav class="toc" aria-label="@yield('toc-label')">
      <h2>@yield('toc-label')</h2>
      <ol>@yield('toc')</ol>
    </nav>

    <div class="prose">
      @yield('body')
    </div>

    <footer>
      <a href="{{ url("/{$otherDoc}/{$locale}") }}">@yield('other-doc')</a>
      <span>@yield('footer-contact')</span>
    </footer>
  </div>
</body>
</html>
