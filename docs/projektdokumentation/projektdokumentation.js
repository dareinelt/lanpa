// Nummeriert Abbildungen und erzeugt Inhalts- und Abbildungsverzeichnis.
(function () {
  // Überschrift, Einleitungsabsätze und erste Abbildung zusammenhalten (keine verwaisten Überschriften).
  document.querySelectorAll('section.chapter h2, section.chapter h3').forEach(function (h) {
    var group = [h];
    var n = h.nextElementSibling;
    while (n && n.tagName === 'P' && group.length < 3) { group.push(n); n = n.nextElementSibling; }
    if (!n || !(n.tagName === 'FIGURE' || n.classList.contains('fig-grid'))) return;
    group.push(n);
    var wrap = document.createElement('div');
    wrap.className = 'keep';
    h.parentNode.insertBefore(wrap, h);
    group.forEach(function (el) { wrap.appendChild(el); });
  });

  var figs = document.querySelectorAll('section figure figcaption');
  var figList = document.getElementById('fig-list');
  figs.forEach(function (cap, i) {
    var n = i + 1;
    var id = 'abb-' + n;
    cap.parentElement.id = id;
    var text = cap.textContent.trim();
    cap.innerHTML = '<span class="fig-label">Abb. ' + n + '</span> ' + cap.innerHTML;
    if (figList) {
      var li = document.createElement('li');
      li.innerHTML = '<span class="fig-label">Abb. ' + n + '</span><span class="title"><a href="#' + id + '"></a></span><span class="pg" data-target="' + id + '"></span>';
      li.querySelector('a').textContent = text;
      figList.appendChild(li);
    }
  });

  var toc = document.getElementById('toc-list');
  if (toc) {
    document.querySelectorAll('section.chapter').forEach(function (sec) {
      var h1 = sec.querySelector('h1.chapter-title');
      add(sec.id, h1.textContent, false);
      sec.querySelectorAll('h2[id]').forEach(function (h2) { add(h2.id, h2.textContent, true); });
    });
  }
  function add(id, text, sub) {
    var li = document.createElement('li');
    if (sub) li.className = 'sub';
    li.innerHTML = '<span class="title"><a href="#' + id + '"></a></span><span class="pg" data-target="' + id + '"></span>';
    li.querySelector('a').textContent = text.replace(/\s+/g, ' ').trim();
    toc.appendChild(li);
  }

  // Wird vom Build-Skript genutzt: Marken setzen bzw. Seitenzahlen eintragen.
  window.__setMarkers = function () {
    document.querySelectorAll('[data-target]').forEach(function (pg) {
      var el = document.getElementById(pg.getAttribute('data-target'));
      var host = el.tagName === 'SECTION' ? el.querySelector('h1.chapter-title') : (el.tagName === 'FIGURE' ? el.querySelector('figcaption') : el);
      if (host.querySelector('.pg-marker')) return;
      var m = document.createElement('span');
      m.className = 'pg-marker';
      m.textContent = 'QQM' + pg.getAttribute('data-target').replace(/-/g, 'X') + 'QQE';
      host.appendChild(m);
    });
    return Array.prototype.map.call(document.querySelectorAll('[data-target]'), function (pg) { return pg.getAttribute('data-target'); });
  };
  window.__setPages = function (pages) {
    document.querySelectorAll('.pg-marker').forEach(function (m) { m.remove(); });
    document.querySelectorAll('[data-target]').forEach(function (pg) {
      var p = pages[pg.getAttribute('data-target')];
      pg.textContent = p ? String(p) : '';
    });
  };
})();
