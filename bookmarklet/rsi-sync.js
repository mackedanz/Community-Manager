/*
 * RSI-Sync als Lesezeichen (Bookmarklet). Läuft nur, wenn du es auf deiner RSI-Pledge-Seite
 * anklickst, in deiner eigenen RSI-Sitzung. Es liest die Pledge-Seiten, die du auch selbst siehst,
 * und übergibt das Ergebnis an ein Fenster des Community-Hangars (__APP_URL__). Dort prüfst und
 * bestätigst du die Übernahme. RSI-Zugangsdaten werden nie gelesen oder gesendet.
 *
 * Die App baut aus dieser Datei den javascript:-Link (siehe src/lib/bookmarklet.ts).
 * Kommentare mit Doppel-Schrägstrich sind hier nicht erlaubt, weil der Link einzeilig wird.
 */
(function () {
  "use strict";

  var APP_URL = "__APP_URL__";
  var RSI_ORIGIN = "https://robertsspaceindustries.com";
  var PAGE_SIZE = 100; /* RSI liefert derzeit trotzdem nur 10 pro Seite */
  var MAX_PAGES = 200;
  var DELAY_MIN_MS = 400;
  var DELAY_MAX_MS = 900;

  /* ---------- Auslesen der Pledge-Seiten (reine Funktionen, testbar) ---------- */

  function text(el) {
    return el ? el.textContent.replace(/\s+/g, " ").trim() : "";
  }

  function inputValue(root, cls) {
    var el = root.querySelector("." + cls);
    return el && el.value ? el.value.trim() : "";
  }

  function parseItem(el) {
    return {
      title: text(el.querySelector(".title")),
      kind: text(el.querySelector(".kind")),
      liner: text(el.querySelector(".liner")),
      itemCustomName: text(el.querySelector(".custom-name-text")),
    };
  }

  function parsePledge(li) {
    var pledgeId = inputValue(li, "js-pledge-id");
    var rawName = inputValue(li, "js-pledge-name");
    if (!pledgeId || !rawName) return null;

    return {
      pledgeId: pledgeId,
      /* Gutscheincodes stehen hinter einem Doppelpunkt und werden nicht übertragen. */
      pledgeName: rawName.split(":")[0].trim(),
      items: Array.prototype.slice
        .call(li.querySelectorAll(".item"))
        .filter(function (el) {
          return !el.closest(".without-images");
        })
        .map(parseItem)
        .filter(function (i) {
          return i.title;
        }),
      alsoContains: Array.prototype.slice
        .call(li.querySelectorAll(".without-images .item .title"))
        .map(function (t) {
          return { title: text(t) };
        })
        .filter(function (i) {
          return i.title;
        }),
    };
  }

  /** Liest eine Pledge-Seite. hasNext ist wahr, wenn es eine weitere Seite gibt. */
  function parsePledgePage(doc) {
    var handle = text(doc.querySelector(".c-account-sidebar__profile-info-handle, .a-handleName"));
    var list = doc.querySelector(".list-items");
    var pledges = list
      ? Array.prototype.slice.call(list.children).map(parsePledge).filter(Boolean)
      : [];
    return { handle: handle, pledges: pledges, hasNext: !!doc.querySelector(".raquo") };
  }

  function buildExport(handle, pledges) {
    return { type: "hangarexport", version: 2, handle: handle || null, pledges: pledges };
  }

  /* Für Tests (Node/jsdom); im Browser gibt es kein "module". */
  if (typeof module !== "undefined" && module.exports) {
    module.exports = { parsePledgePage: parsePledgePage, buildExport: buildExport };
    return;
  }

  /* ---------- Ablauf auf der RSI-Seite ---------- */

  var APP_WINDOW = "community-hangar-rsi-sync";

  if (location.origin === new URL(APP_URL).origin && typeof window.communityHangarStartSync === "function") {
    /* Im Community-Hangar angeklickt: RSI im neuen Tab öffnen, die Vorschau erscheint in dieser Seite. */
    window.communityHangarStartSync();
    return;
  }

  if (location.origin !== RSI_ORIGIN || !/\/account\/pledges/.test(location.pathname)) {
    /* Nicht die aktuelle Seite ersetzen: RSI in einem neuen Fenster öffnen. */
    if (!window.open(RSI_ORIGIN + "/en/account/pledges", "community-hangar-rsi")) {
      alert("Der neue Tab wurde vom Browser blockiert. Bitte Pop-ups erlauben und noch einmal klicken, oder deine RSI-Pledge-Seite selbst öffnen.");
    }
    return;
  }
  if (window.__communityHangarSync) return;
  window.__communityHangarSync = true;

  /* Das Fenster sofort öffnen, solange der Klick noch "frisch" ist; sonst blockiert der Browser es. */
  var appOrigin = new URL(APP_URL).origin;
  /* Hat der Community-Hangar dieses RSI-Fenster selbst geöffnet, geht die Übergabe an seinen Tab zurück. */
  var win = null;
  try {
    if (window.name === APP_WINDOW && window.opener && !window.opener.closed) win = window.opener;
  } catch (e) {
    win = null;
  }
  var viaOpener = !!win;
  if (!win) win = window.open(APP_URL + "/sync/receive", "community-hangar-sync");

  var box = document.createElement("div");
  box.style.cssText =
    "position:fixed;right:16px;bottom:16px;z-index:2147483647;width:300px;padding:12px;" +
    "background:#0b1220;color:#e5e7eb;border:1px solid #334155;border-radius:8px;" +
    "font:13px/1.4 system-ui,sans-serif;box-shadow:0 4px 20px rgba(0,0,0,.5)";
  box.innerHTML = '<div style="font-weight:600;margin-bottom:6px">Community-Hangar</div><div></div>';
  document.body.appendChild(box);
  var statusEl = box.lastChild;
  function setStatus(msg) {
    statusEl.textContent = msg;
  }
  function finish(msg, closeAfterMs) {
    setStatus(msg);
    window.__communityHangarSync = false;
    if (closeAfterMs) {
      setTimeout(function () {
        box.remove();
      }, closeAfterMs);
    }
  }

  if (!win) {
    finish("Das Fenster des Community-Hangars wurde blockiert. Bitte Pop-ups für diese Seite erlauben und das Lesezeichen erneut anklicken.");
    return;
  }

  function sleep(ms) {
    return new Promise(function (resolve) {
      setTimeout(resolve, ms);
    });
  }

  function pledgesPath() {
    var m = location.pathname.match(/^(.*\/account\/pledges)/);
    return m ? m[1] : "/account/pledges";
  }

  /* Fortschritt an das Community-Hangar-Fenster melden (zeigt dort "Seite X, Y Pledges"); Fehler sind egal. */
  function notify(page, count) {
    try {
      win.postMessage({ type: "ch-progress", page: page, pledges: count }, appOrigin);
    } catch (e) {
      /* Fenster weg oder noch nicht bereit */
    }
  }

  async function readWholeHangar() {
    var handle = text(document.querySelector(".c-account-sidebar__profile-info-handle, .a-handleName"));
    var pledges = [];
    for (var page = 1; page <= MAX_PAGES; page++) {
      setStatus("Lese Seite " + page + " … (" + pledges.length + " Pledges)");
      notify(page, pledges.length);
      var res = await fetch(pledgesPath() + "?page=" + page + "&pagesize=" + PAGE_SIZE, {
        credentials: "same-origin",
      });
      if (!res.ok) throw new Error("RSI antwortet mit Status " + res.status + ". Bist du angemeldet?");
      var parsed = parsePledgePage(new DOMParser().parseFromString(await res.text(), "text/html"));
      if (parsed.handle) handle = parsed.handle;
      if (parsed.pledges.length === 0) break;
      pledges.push.apply(pledges, parsed.pledges);
      if (!parsed.hasNext) break;
      /* Bewusst langsam, um RSI nicht zu belasten. */
      await sleep(DELAY_MIN_MS + Math.random() * (DELAY_MAX_MS - DELAY_MIN_MS));
    }
    return buildExport(handle, pledges);
  }

  /* Übergabe per postMessage: Das App-Fenster meldet "bereit", wir schicken die Daten. */
  function deliver(data) {
    return new Promise(function (resolve, reject) {
      var timer = setTimeout(function () {
        window.removeEventListener("message", onMessage);
        reject(new Error("Das Community-Hangar-Fenster antwortet nicht. Bist du dort angemeldet?"));
      }, 120000);
      function onMessage(event) {
        if (event.origin !== appOrigin || event.source !== win || !event.data) return;
        if (event.data.type === "ch-ready") {
          win.postMessage({ type: "ch-export", data: data }, appOrigin);
        } else if (event.data.type === "ch-received") {
          clearTimeout(timer);
          window.removeEventListener("message", onMessage);
          resolve();
        }
      }
      window.addEventListener("message", onMessage);
    });
  }

  readWholeHangar()
    .then(function (data) {
      if (data.pledges.length === 0) {
        throw new Error("Keine Pledges gefunden. Ist dein Hangar leer oder hat RSI die Seite geändert?");
      }
      setStatus(data.pledges.length + " Pledges gelesen. Übergebe an den Community-Hangar …");
      return deliver(data).then(function () {
        return data.pledges.length;
      });
    })
    .then(function (count) {
      finish(count + " Pledges übergeben. Bitte im Community-Hangar bestätigen.", 15000);
      try {
        if (!viaOpener) win.focus();
      } catch (e) {
        /* manche Browser erlauben das nicht */
      }
    })
    .catch(function (err) {
      finish("Fehler: " + (err && err.message ? err.message : err));
    });
})();
