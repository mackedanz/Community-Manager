/* Event-Formular: Schiffe aus dem Orga-Hangar wählen, Besatzungsplätze und Mitglieder zuordnen.
   Der Zustand wird beim Absenden als JSON in das Feld "payload" geschrieben. */
(function () {
  "use strict";

  var form = document.getElementById("event-form");
  if (!form) return;
  var cfg = JSON.parse(form.getAttribute("data-config") || "{}");
  var fleet = cfg.fleet || [];
  var members = cfg.members || [];
  var ships = (cfg.ships || []).map(function (s) {
    return {
      catalogItemId: s.catalogItemId || null,
      customName: s.customName || null,
      name: s.name,
      task: s.task || "",
      slots: (s.slots || []).map(function (x) { return { label: x.label, userId: x.userId || "" }; }),
    };
  });

  var shipList = form.querySelector("[data-ships]");
  var noShips = form.querySelector("[data-no-ships]");
  var shipCount = form.querySelector("[data-ship-count]");
  var fleetList = form.querySelector("[data-fleet]");
  var noFleet = form.querySelector("[data-no-fleet]");
  var search = form.querySelector("[data-search]");
  var careerSel = form.querySelector("[data-career]");
  var customInput = form.querySelector("[data-custom]");

  var small = "rounded border border-zinc-700 bg-zinc-900 px-2 py-1 text-sm";
  var btn = "rounded border border-zinc-700 px-2 py-1 text-xs hover:bg-zinc-800";

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function button(label, onClick, aria) {
    var b = el("button", btn, label);
    b.type = "button";
    if (aria) b.setAttribute("aria-label", aria);
    b.addEventListener("click", onClick);
    return b;
  }

  /* Besatzungsplätze passend zur Schiffsgröße: Pilot, Copilot, dann Crew 3 … (bis maximale Besatzung, höchstens 12) */
  function defaultSlots(crewMax) {
    var n = Math.max(1, Math.min(crewMax || 1, 12));
    var out = [];
    for (var i = 0; i < n; i++) out.push({ label: i === 0 ? "Pilot" : i === 1 ? "Copilot" : "Crew " + (i + 1), userId: "" });
    return out;
  }

  function renderShips() {
    shipList.replaceChildren();
    shipCount.textContent = String(ships.length);
    noShips.classList.toggle("hidden", ships.length > 0);

    ships.forEach(function (s) {
      var li = el("li", "space-y-2 rounded border border-zinc-800 p-3");

      var head = el("div", "flex flex-wrap items-center gap-2");
      head.appendChild(el("span", "font-medium", s.name));
      var task = el("input", small + " min-w-0 flex-1");
      task.value = s.task;
      task.placeholder = "Aufgabe, z. B. Eskorte";
      task.maxLength = 80;
      task.setAttribute("aria-label", "Aufgabe für " + s.name);
      task.addEventListener("input", function () { s.task = task.value; });
      head.appendChild(task);
      head.appendChild(button("Schiff entfernen", function () {
        ships.splice(ships.indexOf(s), 1);
        renderShips();
      }));
      li.appendChild(head);

      var slotList = el("ul", "space-y-1");
      s.slots.forEach(function (slot) {
        var row = el("li", "flex flex-wrap items-center gap-2");
        var label = el("input", small + " w-32");
        label.value = slot.label;
        label.maxLength = 40;
        label.setAttribute("aria-label", "Bezeichnung des Platzes");
        label.addEventListener("input", function () { slot.label = label.value; });
        row.appendChild(label);

        var sel = el("select", small + " w-48");
        sel.setAttribute("aria-label", "Mitglied für " + slot.label);
        var open = el("option", "", "– offen –");
        open.value = "";
        sel.appendChild(open);
        members.forEach(function (m) {
          var o = el("option", "", m.name);
          o.value = m.id;
          if (m.id === slot.userId) o.selected = true;
          sel.appendChild(o);
        });
        sel.addEventListener("change", function () { slot.userId = sel.value; });
        row.appendChild(sel);

        row.appendChild(button("✕", function () {
          s.slots.splice(s.slots.indexOf(slot), 1);
          renderShips();
        }, "Platz " + slot.label + " entfernen"));
        slotList.appendChild(row);
      });
      li.appendChild(slotList);

      li.appendChild(button("+ Platz", function () {
        s.slots.push({ label: "Crew " + (s.slots.length + 1), userId: "" });
        renderShips();
      }));
      shipList.appendChild(li);
    });
  }

  function renderFleet() {
    var q = search.value.trim().toLowerCase();
    var career = careerSel.value;
    fleetList.replaceChildren();
    noFleet.classList.toggle("hidden", fleet.length > 0);
    fleetList.classList.toggle("hidden", fleet.length === 0);

    var matches = fleet.filter(function (f) {
      var okText = !q || f.name.toLowerCase().indexOf(q) >= 0 || (f.manufacturer || "").toLowerCase().indexOf(q) >= 0;
      var okCareer = !career || (f.career || "").toLowerCase() === career.toLowerCase();
      return okText && okCareer;
    });
    if (fleet.length > 0 && matches.length === 0) {
      fleetList.appendChild(el("li", "p-2 text-sm text-zinc-500", "Keine Treffer."));
    }
    matches.forEach(function (f) {
      var li = el("li", "flex items-center gap-3 p-2 text-sm");
      var info = el("div", "min-w-0 flex-1");
      info.appendChild(el("span", "font-medium", f.name));
      var crew = "";
      if (f.crewMin != null) crew = " · Crew " + f.crewMin + (f.crewMax && f.crewMax !== f.crewMin ? "–" + f.crewMax : "");
      info.appendChild(el("span", "ml-1 text-xs text-zinc-500", (f.manufacturer || "–") + " · " + f.count + "× in der Orga" + crew));
      li.appendChild(info);
      li.appendChild(button("Hinzufügen", function () {
        ships.push({
          catalogItemId: f.catalogItemId, customName: f.catalogItemId ? null : f.name, name: f.name,
          task: "", slots: defaultSlots(f.crewMax != null ? f.crewMax : f.crewMin),
        });
        renderShips();
      }));
      fleetList.appendChild(li);
    });
  }

  /* Karriere-Auswahl aus den vorhandenen Werten */
  var careers = {};
  fleet.forEach(function (f) { if (f.career) careers[f.career] = true; });
  Object.keys(careers).sort().forEach(function (c) {
    var o = el("option", "", c);
    o.value = c;
    careerSel.appendChild(o);
  });

  search.addEventListener("input", renderFleet);
  careerSel.addEventListener("change", renderFleet);
  form.querySelector("[data-add-custom]").addEventListener("click", function () {
    var name = customInput.value.trim();
    if (!name) return;
    ships.push({ catalogItemId: null, customName: name, name: name, task: "", slots: defaultSlots(1) });
    customInput.value = "";
    renderShips();
  });
  /* Enter im Suchfeld soll nicht das ganze Formular absenden */
  [search, customInput].forEach(function (i) {
    i.addEventListener("keydown", function (e) { if (e.key === "Enter") e.preventDefault(); });
  });

  form.addEventListener("submit", function () {
    var v = function (name) { return form.elements[name].value; };
    form.elements.payload.value = JSON.stringify({
      title: v("title"), description: v("description"), location: v("location"),
      startsAt: v("startsAt"), endsAt: v("endsAt"),
      ships: ships.map(function (s) {
        return {
          catalogItemId: s.catalogItemId, customName: s.customName, task: s.task,
          slots: s.slots.map(function (x) { return { label: x.label, userId: x.userId || null }; }),
        };
      }),
    });
  });

  renderShips();
  renderFleet();
})();
