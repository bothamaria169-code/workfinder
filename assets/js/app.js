/* WorkFinder — shared behaviour for the job seeker app and the admin dashboard. */
(function () {
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function toast(msg) {
    var t = $('#toast'); if (!t) return;
    t.textContent = msg; t.classList.add('show');
    clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('show'); }, 2600);
  }
  window.wfToast = toast;

  function post(path, data) {
    var body = new FormData();
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    body.append('_csrf', WF.csrf);
    return fetch(WF.url + '/' + path, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  window.wfPost = post;

  // Sidebar + theme
  var side = $('#admSide'), scrim = $('#admScrim');
  if ($('#admBurger')) $('#admBurger').onclick = function () { side.classList.add('open'); scrim.classList.add('show'); };
  if (scrim) scrim.onclick = function () { side.classList.remove('open'); scrim.classList.remove('show'); };
  if ($('#themeBtn')) $('#themeBtn').onclick = function () {
    var r = document.documentElement, dark = r.dataset.theme ? r.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
    r.dataset.theme = dark ? 'light' : 'dark';
    try { localStorage.setItem('wf-theme', r.dataset.theme); } catch (e) {}
  };

  $$('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
  });
  $$('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () { navigator.clipboard.writeText(b.dataset.copy); b.innerHTML = '<i class="fa-solid fa-check"></i>'; });
  });
  $$('form[data-busy]').forEach(function (f) {
    f.addEventListener('submit', function () { var b = f.querySelector('[type=submit]'); if (b) { b.disabled = true; } });
  });

  // Save / unsave a job
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-save]'); if (!b) return;
    e.preventDefault();
    post('ajax/save_job.php', { job_id: b.dataset.save }).then(function (r) {
      if (!r.ok) { toast(r.error || 'Could not save'); return; }
      b.classList.toggle('is-on', r.saved);
      b.innerHTML = '<i class="fa-' + (r.saved ? 'solid' : 'regular') + ' fa-star"></i>' + (r.saved ? 'Saved' : 'Save');
      toast(r.saved ? 'Saved — it\'s on your Applications board too' : 'Removed from saved jobs');
    });
  });

  // Follow / unfollow a company
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-follow]'); if (!b) return;
    e.preventDefault();
    post('ajax/follow_company.php', { company_id: b.dataset.follow }).then(function (r) {
      if (!r.ok) return;
      b.innerHTML = r.following ? '<i class="fa-solid fa-check"></i> Following' : '<i class="fa-solid fa-plus"></i> Follow';
      toast(r.following ? 'Following — new jobs show on your dashboard' : 'Unfollowed');
    });
  });

  // Skill tag input: <div class="tagbox" data-name="skills[]" data-options='[{"id":1,"name":"PHP"}]'>
  $$('.tagbox').forEach(function (box) {
    var opts = JSON.parse(box.dataset.options || '[]'), name = box.dataset.name;
    var input = box.querySelector('input'), list = document.createElement('div');
    list.className = 'suggest__list'; list.hidden = true; box.parentNode.classList.add('suggest'); box.parentNode.appendChild(list);
    function chosen() { return $$('input[type=hidden]', box).map(function (i) { return i.value; }); }
    function add(o) {
      if (chosen().indexOf(String(o.id)) !== -1) return;
      var s = document.createElement('span'); s.className = 'skill';
      s.innerHTML = '<input type="hidden" name="' + name + '" value="' + o.id + '">' + o.name.replace(/</g, '&lt;') + ' <button type="button" aria-label="Remove">&times;</button>';
      s.querySelector('button').onclick = function () { s.remove(); };
      box.insertBefore(s, input); input.value = ''; list.hidden = true;
    }
    (box.dataset.selected ? JSON.parse(box.dataset.selected) : []).forEach(add);
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase(), have = chosen();
      if (!q) { list.hidden = true; return; }
      var m = opts.filter(function (o) { return o.name.toLowerCase().indexOf(q) !== -1 && have.indexOf(String(o.id)) === -1; }).slice(0, 8);
      list.innerHTML = ''; m.forEach(function (o, i) {
        var d = document.createElement('div'); d.textContent = o.name; if (!i) d.className = 'on';
        d.onmousedown = function (ev) { ev.preventDefault(); add(o); input.focus(); };
        list.appendChild(d);
      });
      list.hidden = !m.length;
      list._m = m;
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); if (list._m && list._m.length && !list.hidden) add(list._m[0]); }
      if (e.key === 'Backspace' && !input.value) { var last = $$('.skill', box).pop(); if (last) last.remove(); }
    });
    input.addEventListener('blur', function () { setTimeout(function () { list.hidden = true; }, 120); });
    box.addEventListener('click', function () { input.focus(); });
  });

  // Applications board: drag a card to another column to change its status
  var drag = null;
  $$('.app-card[draggable]').forEach(function (c) {
    c.addEventListener('dragstart', function () { drag = c; });
  });
  $$('.col[data-status]').forEach(function (col) {
    col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('drop'); });
    col.addEventListener('dragleave', function () { col.classList.remove('drop'); });
    col.addEventListener('drop', function (e) {
      e.preventDefault(); col.classList.remove('drop');
      if (!drag) return;
      col.appendChild(drag);
      post('ajax/app_status.php', { id: drag.dataset.id, status: col.dataset.status }).then(function (r) {
        toast(r.ok ? 'Moved to ' + col.dataset.label : (r.error || 'Could not update'));
        $$('.col[data-status]').forEach(function (c) { c.querySelector('.col__head b').textContent = c.querySelectorAll('.app-card').length; });
      });
      drag = null;
    });
  });
})();
