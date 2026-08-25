/* ================================================================
   FoleyTimePro — API Reference interactions
   Vanilla, no dependencies: this page must work on an air-gapped
   install where no CDN is reachable.

   Content is server-rendered; this only layers on filtering,
   scroll-spy, snippet tabs and copy-to-clipboard.
   ================================================================ */
(function () {
  'use strict';

  var root = document.querySelector('.od');
  if (!root) return;

  /* ── Live demo key ──────────────────────────────────────────── */
  // Every rendered snippet carries one of two plain-text placeholders —
  // $FTP_KEY in cURL, YOUR_API_KEY in the Power BI M getting-started query.
  // Neither contains an HTML-special character, so replacing them via
  // innerHTML is safe without re-running server-side syntax tinting: the
  // token reads identically whether the source was escaped or not, unlike
  // the earlier `<your-api-key>` placeholder this replaced (which the
  // htmlspecialchars() pass in odTintM() would have turned into
  // `&lt;your-api-key&gt;`, silently failing a naive string replace).
  var genBtn      = document.getElementById('odGenKeyBtn');
  var genSpinner  = document.getElementById('odGenKeySpinner');
  var keyIdle     = document.getElementById('odKeyIdle');
  var keyResult   = document.getElementById('odKeyResult');

  if (genBtn) {
    genBtn.addEventListener('click', function () {
      var tokenEl = document.querySelector('meta[name="csrf-token"]');
      var token = tokenEl ? tokenEl.getAttribute('content') : '';

      genBtn.disabled = true;
      if (genSpinner) genSpinner.style.display = '';

      fetch(location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=generate_demo_key&csrf=' + encodeURIComponent(token)
      })
        .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
        .then(function (res) {
          if (!res.ok || !res.data || !res.data.success) {
            var msg = (res.data && res.data.error) || 'Could not create a key.';
            showKeyError(msg);
            return;
          }
          applyDemoKey(res.data.key, res.data.name, res.data.expires_in);
        })
        .catch(function () { showKeyError('Request failed — check your connection and try again.'); })
        .finally(function () {
          genBtn.disabled = false;
          if (genSpinner) genSpinner.style.display = 'none';
        });
    });
  }

  function applyDemoKey(key, name, expiresIn) {
    // Every code pane on the page, not just this panel's own snippet — the
    // whole point is that scrolling down to any operation's cURL/Power BI
    // tab is now paste-and-run too.
    root.querySelectorAll('.od-pane pre code').forEach(function (code) {
      code.innerHTML = code.innerHTML
        .split('$FTP_KEY').join(key)
        .split('YOUR_API_KEY').join(key);
    });

    if (keyIdle) keyIdle.style.display = 'none';
    if (keyResult) {
      keyResult.style.display = 'block';
      keyResult.innerHTML =
        '<strong>Key generated — inserted into every example on this page.</strong>' +
        '<div style="font-family:var(--od-mono);font-size:12px;margin-top:8px;padding:8px 10px;' +
        'background:var(--od-code-bg);color:var(--od-signal-fill);border-radius:6px;word-break:break-all">' +
        escapeHtml(key) + '</div>' +
        '<div style="font-size:11.5px;color:var(--od-muted);margin-top:8px">' +
        escapeHtml(name) + ' · read-only scopes · expires in ' + escapeHtml(expiresIn) +
        ' · <a href="api_keys.php" style="color:var(--od-teal)">manage or revoke</a></div>';
    }
  }

  function showKeyError(msg) {
    if (keyResult) {
      keyResult.style.display = 'block';
      keyResult.innerHTML = '<strong style="color:var(--od-rose)">' + escapeHtml(msg) + '</strong>';
    }
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  /* ── Snippet tabs ───────────────────────────────────────────── */
  root.addEventListener('click', function (e) {
    var tab = e.target.closest('.od-tab');
    if (!tab) return;

    var group = tab.closest('.od-code');
    if (!group) return;

    var target = tab.getAttribute('data-pane');
    group.querySelectorAll('.od-tab').forEach(function (t) {
      t.classList.toggle('active', t === tab);
      t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
    });
    group.querySelectorAll('.od-pane').forEach(function (p) {
      p.classList.toggle('active', p.getAttribute('data-pane') === target);
    });
  });

  /* ── Copy to clipboard ──────────────────────────────────────── */
  root.addEventListener('click', function (e) {
    var btn = e.target.closest('.od-copy');
    if (!btn) return;

    var pane = btn.closest('.od-pane');
    var pre = pane && pane.querySelector('pre');
    if (!pre) return;

    // textContent, not innerHTML: the syntax tinting wraps spans that must not
    // end up in what the user pastes into a terminal.
    var text = pre.textContent.replace(/ /g, ' ');

    copyText(text).then(function (ok) {
      var original = btn.getAttribute('data-label') || 'Copy';
      btn.textContent = ok ? 'Copied' : 'Press ⌘C';
      btn.classList.toggle('done', ok);
      window.setTimeout(function () {
        btn.textContent = original;
        btn.classList.remove('done');
      }, 1600);
    });
  });

  function copyText(text) {
    // navigator.clipboard needs a secure context. Plenty of these installs run
    // on plain http over a LAN, so fall back rather than silently doing nothing.
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(
        function () { return true; },
        function () { return legacyCopy(text); }
      );
    }
    return Promise.resolve(legacyCopy(text));
  }

  function legacyCopy(text) {
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return ok;
    } catch (err) {
      return false;
    }
  }

  /* ── Sidebar filter ─────────────────────────────────────────── */
  var search = document.getElementById('odSearch');
  var navLinks = Array.prototype.slice.call(root.querySelectorAll('.od-nav a[data-search]'));
  var navGroups = Array.prototype.slice.call(root.querySelectorAll('.od-nav-group'));
  var navEmpty = root.querySelector('.od-nav-empty');

  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      var hits = 0;

      navLinks.forEach(function (a) {
        var match = q === '' || a.getAttribute('data-search').indexOf(q) !== -1;
        a.style.display = match ? '' : 'none';
        if (match) hits++;
      });

      // Hide a group heading when every child under it filtered out.
      navGroups.forEach(function (g) {
        var visible = g.querySelectorAll('a[data-search]:not([style*="none"])').length;
        g.style.display = visible ? '' : 'none';
      });

      if (navEmpty) navEmpty.style.display = hits === 0 ? 'block' : 'none';
    });

    // "/" focuses search, matching the convention of every docs site people
    // already use. Escape clears and blurs.
    document.addEventListener('keydown', function (e) {
      var tag = (e.target.tagName || '').toLowerCase();
      var typing = tag === 'input' || tag === 'textarea' || e.target.isContentEditable;

      if (e.key === '/' && !typing && !e.metaKey && !e.ctrlKey) {
        e.preventDefault();
        search.focus();
        search.select();
      } else if (e.key === 'Escape' && e.target === search) {
        search.value = '';
        search.dispatchEvent(new Event('input'));
        search.blur();
      }
    });
  }

  /* ── Scroll-spy ─────────────────────────────────────────────── */
  // Observe ONLY elements the sidebar actually links to. Watching every
  // .od-section as well would include the per-tag wrappers (#reports,
  // #employees, …), which have no nav entry — once one of those became the
  // topmost visible target the highlight resolved to nothing and every entry
  // was cleared, so the indicator vanished the moment you scrolled past
  // Scopes. Deriving targets from the links keeps the two sets in lockstep,
  // and sidesteps the nesting ambiguity of a section that contains operations.
  var byId = {};
  var targets = [];
  navLinks.forEach(function (a) {
    var href = a.getAttribute('href') || '';
    if (href.charAt(0) !== '#') return;
    var id = href.slice(1);
    var el = document.getElementById(id);
    if (!el) return;
    byId[id] = a;
    targets.push(el);
  });

  var currentId = null;

  function activate(id) {
    if (!id || id === currentId || !byId[id]) return;
    currentId = id;
    navLinks.forEach(function (a) { a.classList.remove('active'); });
    byId[id].classList.add('active');
    scrollNavIntoView(byId[id]);
  }

  if (targets.length && 'IntersectionObserver' in window) {
    var visible = new Set();

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) visible.add(entry.target.id);
        else visible.delete(entry.target.id);
      });
      resolve();
    }, {
      // Bias the band toward the upper third: the item you are reading is the
      // one near the top of the viewport, not the middle.
      rootMargin: '-70px 0px -62% 0px',
      threshold: 0
    });

    targets.forEach(function (t) { observer.observe(t); });

    function resolve() {
      // At the end of the document the remaining cards can sit entirely below
      // the detection band, which never scrolls far enough to reach them — the
      // highlight would freeze on whichever card last qualified. Once we are
      // within a viewport-height of the bottom, hand it to the last target.
      var scrollBottom = window.innerHeight + window.scrollY;
      if (scrollBottom >= document.documentElement.scrollHeight - 4) {
        activate(targets[targets.length - 1].id);
        return;
      }

      // Highlight the highest visible section rather than the last one to fire,
      // so scrolling up selects the right entry instead of lagging one behind.
      var top = null;
      var topY = Infinity;
      visible.forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        var y = el.getBoundingClientRect().top;
        if (y < topY) { topY = y; top = id; }
      });

      // Nothing in the band (a card taller than the band, mid-scroll): keep the
      // existing highlight rather than clearing it to nothing.
      if (top) activate(top);
    }

    // Give the sidebar a correct highlight before the first scroll, including
    // when the page opens on a deep link.
    window.addEventListener('scroll', resolve, { passive: true });
    window.addEventListener('resize', resolve);

    var initial = location.hash ? location.hash.slice(1) : null;
    if (initial && byId[initial]) activate(initial);
    else activate(targets[0].id);
    resolve();
  }

  function scrollNavIntoView(link) {
    var nav = root.querySelector('.od-nav');
    if (!nav || nav.scrollHeight <= nav.clientHeight) return;

    var lr = link.getBoundingClientRect();
    var nr = nav.getBoundingClientRect();
    if (lr.top < nr.top + 8) {
      nav.scrollTop -= (nr.top + 8 - lr.top);
    } else if (lr.bottom > nr.bottom - 8) {
      nav.scrollTop += (lr.bottom - nr.bottom + 8);
    }
  }
})();
