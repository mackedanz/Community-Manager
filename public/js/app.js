/* Gemeinsames Verhalten aller Seiten: Hell/Dunkel-Umschalter und das Orga-Menü. */
(function () {
  function theme() {
    return document.documentElement.dataset.theme === "light" ? "light" : "dark";
  }

  function paintToggles() {
    var t = theme();
    document.querySelectorAll("[data-theme-toggle]").forEach(function (b) {
      b.textContent = t === "dark" ? "☀ Hell" : "☾ Dunkel";
      var label = t === "dark" ? "Zum hellen Modus wechseln" : "Zum dunklen Modus wechseln";
      b.title = label;
      b.setAttribute("aria-label", label);
    });
  }

  document.addEventListener("click", function (e) {
    var toggle = e.target.closest && e.target.closest("[data-theme-toggle]");
    if (toggle) {
      var next = theme() === "dark" ? "light" : "dark";
      document.documentElement.dataset.theme = next;
      try {
        localStorage.setItem("theme", next);
      } catch (err) {
        /* Speichern nicht möglich (z. B. privater Modus): Wahl gilt nur für diese Seite. */
      }
      paintToggles();
    }
  });

  // Orga-Menü: schließt nach der Auswahl und beim Klick daneben
  document.addEventListener("mousedown", function (e) {
    document.querySelectorAll("details[data-org-menu][open]").forEach(function (d) {
      if (!d.contains(e.target)) d.removeAttribute("open");
    });
  });
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("details[data-org-menu] a");
    if (a) a.closest("details").removeAttribute("open");
  });

  // Einklappbare Bereiche: <button data-collapse="#id"> blendet das Element mit dieser ID ein/aus
  document.addEventListener("click", function (e) {
    var btn = e.target.closest && e.target.closest("[data-collapse]");
    if (!btn) return;
    var target = document.querySelector(btn.getAttribute("data-collapse"));
    if (!target) return;
    var open = target.classList.toggle("hidden") === false;
    btn.setAttribute("aria-expanded", open ? "true" : "false");
    var arrow = btn.querySelector("[data-arrow]");
    if (arrow) arrow.textContent = open ? "▾" : "▸";
  });

  // In die Zwischenablage kopieren: <button data-copy="#id">
  document.addEventListener("click", function (e) {
    var btn = e.target.closest && e.target.closest("[data-copy]");
    if (!btn) return;
    var src = document.querySelector(btn.getAttribute("data-copy"));
    if (!src || !navigator.clipboard) return;
    var original = btn.getAttribute("data-label") || btn.textContent;
    btn.setAttribute("data-label", original);
    navigator.clipboard.writeText(src.textContent.trim()).then(function () {
      btn.textContent = "Kopiert ✓";
      setTimeout(function () { btn.textContent = original; }, 2000);
    }, function () { /* Zugriff verweigert: nichts tun */ });
  });

  // Das Lesezeichen soll gezogen, nicht hier angeklickt werden
  document.addEventListener("click", function (e) {
    var a = e.target.closest && e.target.closest("[data-bookmarklet]");
    if (!a) return;
    e.preventDefault();
    alert("Nicht hier klicken, sondern mit gedrückter Maustaste in die Lesezeichenleiste ziehen. Danach klickst du das Lesezeichen auf deiner RSI-Pledge-Seite an.");
  });

  // Rückfrage vor dem Absenden: <form data-confirm="Wirklich …?">
  document.addEventListener("submit", function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute("data-confirm");
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Filterleisten (<form data-autofilter>): Auswahlen wirken sofort, Texteingaben nach kurzer Pause.
  // Ohne JavaScript bleibt der "Filtern"-Knopf sichtbar und das Formular funktioniert wie bisher.
  (function () {
    var forms = document.querySelectorAll("form[data-autofilter]");
    if (!forms.length) return;
    var timer = null;

    function submit(form, field) {
      clearTimeout(timer);
      try {
        if (field && field.name) sessionStorage.setItem("filter-focus", field.name);
      } catch (err) {
        /* Speichern nicht möglich: nur der Cursor springt nicht zurück ins Feld. */
      }
      if (form.requestSubmit) form.requestSubmit();
      else form.submit();
    }

    forms.forEach(function (form) {
      var button = form.querySelector('button[type="submit"]');
      if (button) button.classList.add("hidden");
      form.addEventListener("change", function (e) {
        if (e.target.matches("select")) submit(form, null);
        else if (e.target.matches('input[type="number"]')) submit(form, e.target);
      });
      form.addEventListener("input", function (e) {
        var t = e.target;
        if (e.isComposing || !t.matches('input[type="search"], input[type="number"]')) return;
        clearTimeout(timer);
        timer = setTimeout(function () { submit(form, t); }, 600);
      });
    });

    // Nach dem Neuladen den Cursor wieder in das Feld setzen, in dem getippt wurde
    try {
      var name = sessionStorage.getItem("filter-focus");
      if (name) {
        sessionStorage.removeItem("filter-focus");
        var f = document.querySelector('form[data-autofilter] [name="' + name + '"]');
        if (f) {
          f.focus();
          if (f.type === "search") f.setSelectionRange(f.value.length, f.value.length);
        }
      }
    } catch (err) { /* egal */ }
  })();

  paintToggles();
})();
