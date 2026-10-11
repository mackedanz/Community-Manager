/* Empfängt die Pledges vom Lesezeichen auf der RSI-Seite (postMessage) und zeigt die Vorschau. */
(function () {
  "use strict";

  var RSI_ORIGIN = "https://robertsspaceindustries.com";
  var root = document.getElementById("receive");
  var kindLabels = {};
  try {
    kindLabels = JSON.parse(root.getAttribute("data-kind-labels") || "{}");
  } catch (e) {
    kindLabels = {};
  }
  var exportData = null;
  var done = function () {};
  var cancelAction = function () { window.close(); };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function show() {
    root.replaceChildren.apply(root, arguments);
  }

  function message(text, cls) {
    show(el("p", cls || "text-zinc-300", text));
  }

  function sendImport(data, dryRun) {
    return fetch("/api/v1/import/hangar" + (dryRun ? "?dryRun=1" : ""), {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(data),
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (body) {
        if (!res.ok) throw new Error((body && body.error) || "Fehler " + res.status);
        return body.plan;
      });
    });
  }

  function preview(plan) {
    var wrap = el("div", "space-y-4");
    var p = el("p");
    var b = function (n) { var x = document.createElement("b"); x.textContent = String(n); return x; };
    p.appendChild(b(plan.matched.length));
    p.appendChild(document.createTextNode(" Einträge erkannt"));
    if (plan.unmatched.length > 0) {
      p.appendChild(document.createTextNode(", "));
      p.appendChild(b(plan.unmatched.length));
      p.appendChild(document.createTextNode(plan.unmatched.length === 1
        ? " Schiff nicht im Katalog (wird mit seinem Namen übernommen)"
        : " Schiffe nicht im Katalog (werden mit ihrem Namen übernommen)"));
    }
    if (plan.others.length > 0) {
      p.appendChild(document.createTextNode(", dazu "));
      p.appendChild(b(plan.others.length));
      p.appendChild(document.createTextNode(" Upgrades, Paints und sonstige Gegenstände"));
    }
    p.appendChild(document.createTextNode("."));
    if (plan.replaced > 0) {
      p.appendChild(document.createTextNode(
        " Deine " + plan.replaced + " bisher von RSI übernommenen Einträge werden ersetzt; manuell hinzugefügte bleiben erhalten."
      ));
    }
    wrap.appendChild(p);

    var ul = el("ul", "max-h-72 divide-y divide-zinc-800 overflow-y-auto rounded border border-zinc-800 text-sm");
    plan.matched.concat(plan.unmatched).forEach(function (e) {
      var li = el("li", "flex justify-between p-2");
      li.appendChild(el("span", "", e.quantity + "× " + e.name));
      li.appendChild(el("span", "text-zinc-400", e.lti ? "LTI" : ""));
      ul.appendChild(li);
    });
    plan.others.forEach(function (o) {
      var li = el("li", "flex justify-between p-2");
      li.appendChild(el("span", "", o.quantity + "× " + o.name));
      li.appendChild(el("span", "text-zinc-400", kindLabels[o.kind] || o.kind));
      ul.appendChild(li);
    });
    wrap.appendChild(ul);

    var buttons = el("div", "flex gap-3");
    var apply = el("button", "rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500 disabled:opacity-40", "In meinen Hangar übernehmen");
    apply.type = "button";
    apply.addEventListener("click", function () {
      apply.disabled = true;
      apply.textContent = "Übernehme …";
      sendImport(exportData, false).then(function () { done(plan); }, function (err) {
        message(err.message, "text-red-400");
      });
    });
    var cancel = el("button", "rounded bg-zinc-800 px-4 py-2 hover:bg-zinc-700", "Abbrechen");
    cancel.type = "button";
    cancel.addEventListener("click", function () { cancelAction(); });
    buttons.appendChild(apply);
    buttons.appendChild(cancel);
    wrap.appendChild(buttons);
    show(wrap);
  }

  /*
   * Empfang starten. source = das Fenster mit der RSI-Seite (Opener auf der Empfangsseite bzw. das von der App
   * geöffnete RSI-Fenster). opts.embedded: Panel in der App-Seite statt eigenem Fenster.
   */
  function receive(rootEl, source, opts) {
    root = rootEl;
    exportData = null;
    var embedded = !!opts.embedded;
    var finished = false;

    var cancelBtn = function (label) {
      var c = el("button", "rounded bg-zinc-800 px-4 py-2 hover:bg-zinc-700", label || "Abbrechen");
      c.type = "button";
      c.addEventListener("click", function () { stop(); opts.onCancel(); });
      return c;
    };
    function waiting(text) {
      var wrap = el("div", "space-y-4");
      wrap.appendChild(el("p", "text-zinc-300", text));
      wrap.appendChild(cancelBtn());
      show(wrap);
    }
    function stop() {
      finished = true;
      clearInterval(ping);
      clearInterval(watch);
      window.removeEventListener("message", onProgress);
    }

    /* Fortschritt des Lesezeichens: Seite und bisher gelesene Pledges (die Gesamtzahl kennt RSI vorab nicht). */
    var progressText = null;
    function onProgress(event) {
      var d = event.data;
      if (event.origin !== RSI_ORIGIN || event.source !== source || !d || d.type !== "ch-progress" || exportData || finished) return;
      var line = "RSI wird gelesen … Seite " + (Number(d.page) || 1) + ", bisher " + (Number(d.pledges) || 0) + " Pledges.";
      if (progressText) {
        progressText.textContent = line;
        return;
      }
      var wrap = el("div", "space-y-4");
      progressText = el("p", "text-zinc-300", line);
      wrap.appendChild(progressText);
      var bar = el("div", "h-2 w-full overflow-hidden rounded bg-zinc-800");
      bar.appendChild(el("div", "h-full w-1/3 animate-pulse rounded bg-indigo-500"));
      wrap.appendChild(bar);
      wrap.appendChild(el("p", "text-sm text-zinc-400", "Das dauert bei großen Hangars etwas, RSI wird bewusst langsam abgefragt. Den RSI-Tab bitte offen lassen."));
      wrap.appendChild(cancelBtn());
      show(wrap);
    }
    window.addEventListener("message", onProgress);

    done = function (plan) {
      stop();
      var total = plan.matched.length + plan.unmatched.length + plan.others.length;
      var wrap = el("div", "space-y-3");
      wrap.appendChild(el("p", "text-green-400", "Fertig: " + total + " Einträge in deinem Hangar."));
      if (embedded) {
        wrap.appendChild(el("p", "text-sm text-zinc-400", "Die Seite wird neu geladen …"));
        setTimeout(function () { location.href = "/hangar"; }, 1200);
      } else {
        var a = el("a", "inline-block rounded bg-indigo-600 px-4 py-2 font-medium hover:bg-indigo-500", "Zu meinem Hangar");
        a.href = "/hangar";
        wrap.appendChild(a);
      }
      show(wrap);
    };
    cancelAction = function () { stop(); opts.onCancel(); };

    if (embedded) {
      waiting("RSI wurde in einem neuen Tab geöffnet. Melde dich dort an (falls nötig) und klicke auf der Pledge-Seite in der Lesezeichenleiste auf „↻ Hangar-Sync“. Hier erscheint dann die Vorschau.");
    } else {
      message("Warte auf die Daten von der RSI-Seite … Das Lesezeichen liest gerade deine Pledges (unten rechts auf der RSI-Seite siehst du den Fortschritt).");
    }

    /* "Bereit" melden, bis die Daten da sind (das Lesezeichen liest die Seiten erst noch). */
    var ping = setInterval(function () {
      try { source.postMessage({ type: "ch-ready" }, RSI_ORIGIN); } catch (e) { /* Fenster weg */ }
    }, 500);
    /* Wird das RSI-Fenster vor der Übergabe geschlossen, nicht ewig warten. */
    var watch = setInterval(function () {
      if (!exportData && !finished && source.closed) {
        stop();
        var wrap = el("div", "space-y-4");
        wrap.appendChild(el("p", "text-zinc-300", "Der RSI-Tab wurde geschlossen, bevor Daten übergeben wurden."));
        wrap.appendChild(cancelBtn("Schließen"));
        show(wrap);
      }
    }, 1000);

    window.addEventListener("message", function onMsg(event) {
      if (event.origin !== RSI_ORIGIN || event.source !== source) return;
      if (!event.data || event.data.type !== "ch-export" || exportData) return;
      window.removeEventListener("message", onMsg);
      clearInterval(ping);
      exportData = event.data.data;
      source.postMessage({ type: "ch-received" }, RSI_ORIGIN);
      if (embedded) {
        /* Das RSI-Fenster hat seine Aufgabe erledigt; kurz warten, damit es die Quittung noch sieht. */
        setTimeout(function () { try { source.close(); } catch (e) { /* egal */ } window.focus(); }, 400);
      }

      message("Daten erhalten, gleiche mit dem Katalog ab …");
      sendImport(exportData, true).then(preview, function (err) {
        message(err.message, "text-red-400");
      });
    });
  }

  /* Eigenes Fenster (vom Lesezeichen geöffnet): Opener ist die RSI-Seite. */
  if (root) {
    if (!window.opener) {
      message("Diese Seite wird vom Lesezeichen „Hangar-Sync“ auf deiner RSI-Pledge-Seite geöffnet. Starte den Sync bitte dort.", "text-red-400");
    } else {
      receive(root, window.opener, { embedded: false, onCancel: function () { window.close(); } });
    }
    return;
  }

  /* In der App: RSI in einem neuen Tab öffnen, die Vorschau erscheint hier. */
  var RSI_WINDOW = "community-hangar-rsi-sync";
  var RSI_PLEDGES = RSI_ORIGIN + "/en/account/pledges";
  var running = null;

  function startSync(url) {
    if (running) { running.focus(); }
    var rsi = window.open(url, RSI_WINDOW);
    if (!rsi) return false; /* blockiert */
    if (running) return true;

    var overlay = el("div", "fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4");
    var box = el("div", "mt-16 w-full max-w-2xl space-y-4 rounded border border-zinc-700 bg-zinc-900 p-6 text-zinc-100");
    box.appendChild(el("h2", "text-xl font-bold", "Hangar von RSI übernehmen"));
    var panel = el("div");
    box.appendChild(panel);
    overlay.appendChild(box);
    document.body.appendChild(overlay);
    running = overlay;
    receive(panel, rsi, {
      embedded: true,
      onCancel: function () {
        try { rsi.close(); } catch (err) { /* egal */ }
        overlay.remove();
        running = null;
      },
    });
    return true;
  }

  /* Das Lesezeichen ruft das auf, wenn es in der App angeklickt wird. */
  window.communityHangarStartSync = function () {
    if (!startSync(RSI_PLEDGES)) {
      alert("Der neue Tab wurde vom Browser blockiert. Bitte Pop-ups für diese Seite erlauben und noch einmal klicken.");
    }
  };

  document.addEventListener("click", function (e) {
    var btn = e.target.closest && e.target.closest("[data-rsi-sync]");
    if (!btn) return;
    if (startSync(btn.href)) e.preventDefault(); /* sonst öffnet der Link normal im neuen Tab */
  });
})();
