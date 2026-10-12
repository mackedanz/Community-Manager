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
  // Eigenes Fenster statt window.confirm, denn Browser können Dialoge der Seite unterdrücken (dann käme nie eine Antwort).
  document.addEventListener("submit", function (e) {
    var form = e.target;
    var msg = form.getAttribute && form.getAttribute("data-confirm");
    if (!msg) return;
    e.preventDefault();
    if (typeof HTMLDialogElement !== "function") {
      if (window.confirm(msg)) form.submit();
      return;
    }
    var dlg = document.createElement("dialog");
    dlg.className = "m-auto max-w-md rounded-lg border border-zinc-700 bg-zinc-900 p-6 text-zinc-100 shadow-xl backdrop:bg-black/60";
    var text = document.createElement("p");
    text.className = "mb-5 text-sm";
    text.textContent = msg;
    var row = document.createElement("div");
    row.className = "flex justify-end gap-3";
    var cancel = document.createElement("button");
    cancel.type = "button";
    cancel.className = "rounded border border-zinc-600 px-4 py-2 text-sm hover:bg-zinc-800";
    cancel.textContent = "Abbrechen";
    var ok = document.createElement("button");
    ok.type = "button";
    ok.className = "rounded bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-600";
    ok.textContent = "Ja, fortfahren";
    row.appendChild(cancel);
    row.appendChild(ok);
    dlg.appendChild(text);
    dlg.appendChild(row);
    document.body.appendChild(dlg);
    var close = function () { dlg.close(); dlg.remove(); };
    cancel.addEventListener("click", close);
    dlg.addEventListener("cancel", function () { dlg.remove(); });
    ok.addEventListener("click", function () { close(); form.submit(); });
    dlg.showModal();
    cancel.focus();
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
