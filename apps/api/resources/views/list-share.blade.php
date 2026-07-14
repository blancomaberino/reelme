<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reelmap · Lista compartida</title>
  <style>
    :root {
      --bg: #f6f7f9; --card: #ffffff; --ink: #14181f; --muted: #66707d;
      --line: #e7eaef; --primary: #208aef; --on-primary: #ffffff;
    }
    @media (prefers-color-scheme: dark) {
      :root { --bg: #0d1013; --card: #161a1f; --ink: #eef1f5; --muted: #98a2b0; --line: #262c34; }
    }
    * { box-sizing: border-box; }
    body { margin: 0; background: var(--bg); color: var(--ink);
      font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px 64px; }
    .brand { display: flex; align-items: center; gap: 8px; font-weight: 800; color: var(--primary); letter-spacing: -.02em; }
    .brand .dot { width: 12px; height: 12px; border-radius: 50%; background: var(--primary); }
    h1 { font-size: 26px; font-weight: 800; letter-spacing: -.02em; margin: 20px 0 4px; }
    .owner { color: var(--muted); font-size: 14px; margin-bottom: 20px; }
    .count { color: var(--muted); font-size: 13px; margin: 0 0 12px; }
    .cta { display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      background: var(--primary); color: var(--on-primary); text-decoration: none; font-weight: 700;
      padding: 12px 20px; border-radius: 999px; margin: 4px 0 24px; }
    ul { list-style: none; margin: 0; padding: 0; }
    li { background: var(--card); border: 1px solid var(--line); border-radius: 14px;
      padding: 14px 16px; margin-bottom: 10px; }
    .name { font-weight: 700; }
    .meta { color: var(--muted); font-size: 13px; margin-top: 2px; }
    .state { color: var(--muted); text-align: center; padding: 64px 16px; }
    .hidden { display: none; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="brand"><span class="dot"></span> Reelmap</div>
    <div id="loading" class="state">Cargando la lista…</div>
    <div id="missing" class="state hidden">Esta lista no existe o es privada.</div>
    <div id="content" class="hidden">
      <h1 id="list-name"></h1>
      <div id="list-owner" class="owner"></div>
      <a id="open-app" class="cta" href="#">Abrir en Reelmap</a>
      <p id="list-count" class="count"></p>
      <ul id="places"></ul>
    </div>
  </div>

  <script>
    // The slug is route-validated to [A-Za-z0-9-]+ before it reaches this view,
    // so it is safe to embed. All API-sourced strings are rendered via
    // textContent only — never innerHTML — so a place/owner name can't inject.
    var SLUG = @json($slug);
    var $ = function (id) { return document.getElementById(id); };
    function show(id) { $(id).classList.remove('hidden'); }
    function hide(id) { $(id).classList.add('hidden'); }

    function priceLine(p) {
      var parts = [];
      if (p.category) parts.push(p.category);
      if (p.city) parts.push(p.city);
      return parts.join(' · ');
    }

    fetch('/api/v1/lists/' + encodeURIComponent(SLUG), { headers: { Accept: 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('not-found'); return r.json(); })
      .then(function (body) {
        var list = body.data;
        $('list-name').textContent = list.name || 'Lista';
        var owner = list.owner;
        $('list-owner').textContent = owner && owner.username ? ('Compartida por @' + owner.username) : '';
        var items = Array.isArray(list.items) ? list.items : [];
        $('list-count').textContent = items.length === 1 ? '1 lugar' : (items.length + ' lugares');
        $('open-app').setAttribute('href', 'reelmap://list/' + encodeURIComponent(SLUG));
        var ul = $('places');
        items.forEach(function (item) {
          var place = item.place || {};
          var li = document.createElement('li');
          var name = document.createElement('div');
          name.className = 'name';
          name.textContent = place.name || '';
          var meta = document.createElement('div');
          meta.className = 'meta';
          meta.textContent = priceLine(place);
          li.appendChild(name);
          if (meta.textContent) li.appendChild(meta);
          ul.appendChild(li);
        });
        hide('loading'); show('content');
      })
      .catch(function () { hide('loading'); show('missing'); });
  </script>
</body>
</html>
