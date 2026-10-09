(function () {
  'use strict';

  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { el.form.submit(); });
  });

  var form = document.getElementById('verzoek-form');
  if (!form || form.dataset.editable !== '1') { return; }

  var statusEl = document.getElementById('save-status');
  var submitBtn = document.getElementById('btn-indienen');
  var idInput = form.querySelector('input[name="id"]');
  var timer = null;
  var saving = false;
  var pending = false;

  function setStatus(text, isError) {
    if (!statusEl) { return; }
    statusEl.textContent = text;
    statusEl.classList.toggle('error', !!isError);
  }

  // Inzenden kan pas als alle verplichte velden gevuld zijn (server controleert opnieuw).
  function requiredFilled() {
    if (!form.querySelector('#bedrijf').value) { return false; }
    var ok = true;
    form.querySelectorAll('[data-verplicht="1"]').forEach(function (el) {
      var combo = el.closest('[data-combo]');
      var value = combo && combo.dataset.strikt === '1' ? combo.querySelector('.combo-value').value : el.value;
      if (!value || !value.trim()) { ok = false; }
    });
    return ok;
  }

  function refreshSubmit() {
    if (submitBtn) {
      var ok = requiredFilled();
      submitBtn.disabled = !ok;
      submitBtn.title = ok ? '' : 'Vul eerst alle verplichte velden (*) in.';
    }
  }

  function save() {
    if (saving) { pending = true; return; }
    saving = true;
    setStatus('Opslaan…');
    var data = new FormData(form);
    data.set('actie', 'opslaan');
    fetch('api.php', { method: 'POST', body: data, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.ok) { throw new Error(json.fout || 'Opslaan mislukt'); }
        if (idInput && !idInput.value && json.id) {
          idInput.value = json.id;
          history.replaceState(null, '', 'verzoek.php?id=' + json.id);
        }
        setStatus('Opgeslagen ' + json.opgeslagen);
      })
      .catch(function (err) { setStatus('Niet opgeslagen: ' + err.message, true); })
      .finally(function () {
        saving = false;
        if (pending) { pending = false; save(); }
      });
  }

  function scheduleSave() {
    refreshSubmit();
    clearTimeout(timer);
    timer = setTimeout(save, 1000);
  }

  form.addEventListener('input', function (e) {
    if (e.target.classList.contains('combo-input')) { return; }
    scheduleSave();
  });
  form.addEventListener('change', function (e) {
    if (e.target.classList.contains('combo-input')) { return; }
    scheduleSave();
  });

  // Combobox (vrije tekst + suggesties) en lookup (strikt: alleen een gekozen bestaande waarde).
  form.querySelectorAll('[data-combo]').forEach(function (combo) {
    var input = combo.querySelector('.combo-input');
    var list = combo.querySelector('.combo-list');
    var strict = combo.dataset.strikt === '1';
    var valueEl = combo.querySelector('.combo-value');
    var labelEl = combo.querySelector('.combo-label');
    var searchTimer = null;
    var seq = 0;

    function close() { list.hidden = true; list.innerHTML = ''; }

    function choose(item) {
      if (strict) {
        valueEl.value = item.waarde;
        labelEl.value = item.label;
        input.value = item.label;
      } else {
        input.value = item.waarde;
      }
      combo.classList.remove('invalid');
      close();
      scheduleSave();
    }

    function search() {
      var bedrijf = form.querySelector('#bedrijf').value;
      var mine = ++seq;
      var url = 'api.php?actie=zoek&type=' + encodeURIComponent(form.dataset.type) + '&veld=' + encodeURIComponent(combo.dataset.veld)
        + '&bedrijf=' + encodeURIComponent(bedrijf) + '&q=' + encodeURIComponent(input.value);
      if (combo.dataset.ouder) {
        var parentEl = form.querySelector('[name="v[' + combo.dataset.ouder + ']"]');
        if (parentEl && parentEl.value) { url += '&ouder=' + encodeURIComponent(parentEl.value); }
      }
      fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (json) {
        if (mine !== seq) { return; }
        list.innerHTML = '';
        if (!json.ok) {
          list.innerHTML = '<li class="combo-empty"></li>';
          list.firstChild.textContent = json.fout || 'Zoeken mislukt';
        } else if (!json.resultaten.length) {
          list.innerHTML = '<li class="combo-empty">Geen resultaten</li>';
        } else {
          json.resultaten.forEach(function (item) {
            var li = document.createElement('li');
            li.textContent = item.label;
            li.setAttribute('role', 'option');
            li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(item); });
            list.appendChild(li);
          });
        }
        list.hidden = false;
      }).catch(function () { /* stil */ });
    }

    input.addEventListener('input', function () {
      if (strict) {
        // Getypte tekst is nog geen keuze: waarde wissen tot er gekozen is.
        valueEl.value = '';
        labelEl.value = '';
        combo.classList.add('invalid');
        refreshSubmit();
      } else {
        scheduleSave();
      }
      clearTimeout(searchTimer);
      searchTimer = setTimeout(search, 250);
    });
    input.addEventListener('focus', function () { if (!input.disabled) { search(); } });
    input.addEventListener('blur', function () {
      setTimeout(close, 150);
      if (strict && !valueEl.value) { input.value = ''; combo.classList.remove('invalid'); scheduleSave(); }
    });
  });

  refreshSubmit();
})();
