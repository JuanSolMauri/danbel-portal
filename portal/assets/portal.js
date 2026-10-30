// Interacción del catálogo: buscador de familias y productos, pestañas y pedido por líneas.
(function () {
  "use strict";

  var euro = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" });
  var norm = function (text) { return (text || "").toLocaleLowerCase("es"); };

  var familySearch = document.getElementById("family-search");
  if (familySearch) {
    familySearch.addEventListener("input", function () {
      var q = norm(familySearch.value);
      document.querySelectorAll(".family-list .brand").forEach(function (el) {
        el.hidden = q !== "" && el.dataset.name.indexOf(q) === -1;
      });
    });
  }

  var results = document.querySelector(".results");
  if (!results) return;

  // Foto ampliada al pulsar la miniatura del producto.
  var lightbox = document.getElementById("lightbox");
  var closeLightbox = function () { lightbox.hidden = true; lightbox.querySelector("img").removeAttribute("src"); };
  results.addEventListener("click", function (ev) {
    var zoom = ev.target.closest("[data-zoom]");
    if (!zoom) return;
    var img = lightbox.querySelector("img");
    img.src = zoom.dataset.zoom;
    img.alt = zoom.getAttribute("aria-label").replace(/^Ampliar foto de /, "");
    lightbox.hidden = false;
    lightbox.querySelector(".lightbox-close").focus();
  });
  lightbox.addEventListener("click", closeLightbox);
  document.addEventListener("keydown", function (ev) { if (ev.key === "Escape" && !lightbox.hidden) closeLightbox(); });

  // Pestañas Pedido / Fotos
  results.querySelectorAll(".tab").forEach(function (tab) {
    tab.addEventListener("click", function () {
      results.querySelectorAll(".tab").forEach(function (t) {
        var active = t === tab;
        t.classList.toggle("active", active);
        t.setAttribute("aria-selected", active ? "true" : "false");
      });
      results.querySelectorAll(".tab-panel").forEach(function (panel) {
        panel.hidden = panel.dataset.panel !== tab.dataset.tab;
      });
    });
  });

  // Buscador de productos
  var productSearch = document.getElementById("product-search");
  var noResults = results.querySelector(".order-no-results");
  productSearch.addEventListener("input", function () {
    var q = norm(productSearch.value);
    var visible = 0;
    results.querySelectorAll(".sergio-section").forEach(function (section) {
      var rows = 0;
      section.querySelectorAll("tbody tr").forEach(function (tr) {
        var show = q === "" || tr.dataset.search.indexOf(q) !== -1;
        tr.hidden = !show;
        if (show) rows++;
      });
      section.hidden = rows === 0;
      visible += rows;
    });
    results.querySelectorAll(".photo-tile[data-search]").forEach(function (tile) {
      tile.hidden = q !== "" && tile.dataset.search.indexOf(q) === -1;
    });
    noResults.hidden = visible > 0;
  });

  // Pedido: las unidades y observaciones se guardan en este navegador por cliente y familia,
  // para no perderlas al cambiar de marca o recargar.
  var storageKey = "danbel-pedido:" + results.dataset.orderKey;
  var saved = {};
  try { saved = JSON.parse(localStorage.getItem(storageKey) || "{}"); } catch (e) { saved = {}; }
  var rows = Array.prototype.slice.call(results.querySelectorAll("tbody tr[data-code]"));
  var notes = document.getElementById("order-notes");
  var buttons = results.querySelectorAll("[data-action]");

  rows.forEach(function (tr) {
    var input = tr.querySelector("input");
    var qty = (saved.lines || {})[tr.dataset.code];
    if (qty) input.value = qty;
    input.addEventListener("input", update);
  });
  notes.value = saved.notes || "";
  notes.addEventListener("input", update);

  function lines() {
    return rows.map(function (tr) {
      var input = tr.querySelector("input");
      var qty = Math.min(100000, Math.max(0, Math.floor(Number(input.value) || 0)));
      var net = Number(tr.dataset.net);
      return { tr: tr, code: tr.dataset.code, ean: tr.dataset.ean, name: tr.dataset.name, type: tr.dataset.type, qty: qty, net: net, total: Math.round(net * qty * 100) / 100 };
    });
  }

  // Productos (P) a precio neto y testers (T) a PVP; los testers son gratis hasta el 10% de los productos.
  // Después, los descuentos 1 y 2 de la ficha del cliente, en cascada sobre el total.
  var dto1 = Number(results.dataset.dto1) || 0;
  var dto2 = Number(results.dataset.dto2) || 0;
  function sums(chosen) {
    var round = function (v) { return Math.round(v * 100) / 100; };
    var products = round(chosen.reduce(function (s, l) { return s + (l.type === "T" ? 0 : l.total); }, 0));
    var testers = round(chosen.reduce(function (s, l) { return s + (l.type === "T" ? l.total : 0); }, 0));
    var free = round(Math.min(testers, products * 0.1));
    var subtotal = round(products + testers - free);
    var d1 = round(subtotal * dto1 / 100);
    var d2 = round((subtotal - d1) * dto2 / 100);
    return { products: products, testers: testers, free: free, d1: d1, d2: d2, total: round(subtotal - d1 - d2) };
  }

  function update() {
    var all = lines();
    var chosen = all.filter(function (l) { return l.qty > 0; });
    var s = sums(chosen);
    var total = s.total;
    var units = chosen.reduce(function (sum, l) { return sum + l.qty; }, 0);
    all.forEach(function (l) { l.tr.querySelector(".line-total").textContent = euro.format(l.total); });
    results.querySelector(".order-summary").textContent = chosen.length + " productos · " + euro.format(total);
    results.querySelector(".order-units").textContent = units + " unidades seleccionadas";
    results.querySelector(".order-total").textContent = "Total " + euro.format(total);
    results.querySelector('[data-sum="products"]').textContent = euro.format(s.products);
    results.querySelector('[data-sum="testers"]').textContent = euro.format(s.testers);
    results.querySelector('[data-sum="free"]').textContent = "− " + euro.format(s.free);
    if (dto1) results.querySelector('[data-sum="dto1"]').textContent = "− " + euro.format(s.d1);
    if (dto2) results.querySelector('[data-sum="dto2"]').textContent = "− " + euro.format(s.d2);
    buttons.forEach(function (b) { b.disabled = chosen.length === 0; });
    var store = { lines: {}, notes: notes.value };
    chosen.forEach(function (l) { store.lines[l.code] = l.qty; });
    try { localStorage.setItem(storageKey, JSON.stringify(store)); } catch (e) { /* sin almacenamiento */ }
    return { chosen: chosen, total: total, sums: s };
  }

  function orderText() {
    var o = update();
    var d = results.dataset;
    return [
      "Pedido " + d.brand + " · Danbel",
      "Cliente: " + (d.customer || "Pendiente de identificar"),
      "Fecha: " + new Date().toLocaleDateString("es-ES"),
      "Descuento de familia: " + d.discount,
      ""
    ].concat(o.chosen.map(function (l) {
      return l.type + " | " + l.code + " | EAN " + (l.ean || "sin EAN") + " | " + l.name + " | " + l.qty + " ud. × " + euro.format(l.net) + " = " + euro.format(l.total);
    })).concat([
      "",
      "Productos (P): " + euro.format(o.sums.products),
      "Testers (T) a PVP: " + euro.format(o.sums.testers),
      "Testers gratis (hasta el 10% de los productos): -" + euro.format(o.sums.free),
      dto1 ? "Descuento 1 (" + dto1.toLocaleString("es-ES") + "%): -" + euro.format(o.sums.d1) : null,
      dto2 ? "Descuento 2 (" + dto2.toLocaleString("es-ES") + "%): -" + euro.format(o.sums.d2) : null,
      "TOTAL: " + euro.format(o.total),
      "IVA y transporte según las condiciones comerciales acordadas.",
      notes.value.trim() ? "Observaciones: " + notes.value.trim() : ""
    ]).filter(function (line) { return line !== null; }).filter(function (line, i, arr) { return line !== "" || (i > 0 && arr[i - 1] !== ""); }).join("\n");
  }

  results.querySelector('[data-action="copy"]').addEventListener("click", function () {
    var button = this;
    navigator.clipboard.writeText(orderText()).then(function () {
      button.textContent = "Copiado";
      setTimeout(function () { button.textContent = "Copiar pedido"; }, 2500);
    });
  });

  results.querySelector('[data-action="clear"]').addEventListener("click", function () {
    if (!confirm("¿Vaciar las unidades de esta familia?")) return;
    rows.forEach(function (tr) { tr.querySelector("input").value = 0; });
    update();
  });

  // Pantalla de resumen multi-marca (Finalizar compra)
  var checkout = document.getElementById("checkout");
  if (checkout) {
    var esc = function (s) { return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); };

    function gatherAllItems() {
      update(); // asegura que el carro actual esté guardado
      var d = results.dataset;
      var prefix = "danbel-pedido:" + (d.customer || "admin") + ":";
      var items = {};
      try {
        for (var i = 0; i < localStorage.length; i++) {
          var key = localStorage.key(i);
          if (key && key.indexOf(prefix) === 0) {
            var saved = JSON.parse(localStorage.getItem(key) || "{}");
            var lines = saved.lines || {};
            for (var code in lines) { if (lines[code] > 0) items[code] = lines[code]; }
          }
        }
      } catch (e) { /* sin localStorage */ }
      return items;
    }

    function renderCheckout(data) {
      var d = results.dataset;
      document.getElementById("checkout-meta").textContent = "Cliente " + (d.customer || "admin") + " · " + new Date().toLocaleDateString("es-ES");
      document.getElementById("checkout-lines").innerHTML = data.brands.map(function (brand) {
        var brandRow = "<tr class='checkout-brand-row'><td colspan='6'>" + esc(brand.name) + "</td></tr>";
        var lineRows = brand.lines.map(function (l) {
          return "<tr>" +
            "<td>" + esc(l.ean || "—") + "<small>" + esc(l.code) + "</small></td>" +
            "<td>" + esc(l.name) + "</td>" +
            "<td><span class='type-badge type-" + (l.type === "T" ? "t" : "p") + "'>" + l.type + "</span></td>" +
            "<td class='num'>" + euro.format(l.net) + "</td>" +
            "<td class='num'>" + l.qty + "</td>" +
            "<td class='num'><strong>" + euro.format(l.total) + "</strong></td>" +
            "</tr>";
        }).join("");
        return brandRow + lineRows;
      }).join("");
      var s = data.sums;
      var d1 = data.dto1, d2 = data.dto2;
      var sumRows = [["Productos (P)", euro.format(s.products)], ["Testers (T) a PVP", euro.format(s.testers)]];
      if (s.free > 0) sumRows.push(["Testers gratis (hasta el 10% de productos)", "− " + euro.format(s.free)]);
      if (d1 && s.d1 > 0) sumRows.push(["Descuento 1 (" + d1.toLocaleString("es-ES") + "%)", "− " + euro.format(s.d1)]);
      if (d2 && s.d2 > 0) sumRows.push(["Descuento 2 (" + d2.toLocaleString("es-ES") + "%)", "− " + euro.format(s.d2)]);
      document.getElementById("checkout-sums").innerHTML = sumRows.map(function (r) {
        return "<div><dt>" + r[0] + "</dt><dd>" + r[1] + "</dd></div>";
      }).join("") + "<div class='checkout-total'><dt>Total (sin IVA)</dt><dd>" + euro.format(data.total) + "</dd></div>";
      var noteText = notes.value.trim();
      document.getElementById("checkout-notes-section").innerHTML = noteText ? "<p class='checkout-notes-text'><strong>Observaciones:</strong> " + esc(noteText) + "</p>" : "";
      checkout.hidden = false;
      checkout.scrollTop = 0;
    }

    function openCheckout() {
      var items = gatherAllItems();
      if (!Object.keys(items).length) return;
      document.getElementById("checkout-lines").innerHTML = "<tr><td colspan='6' style='padding:18px;color:#667488'>Cargando…</td></tr>";
      document.getElementById("checkout-sums").innerHTML = "";
      checkout.hidden = false;
      checkout.scrollTop = 0;
      fetch("app/cart-summary.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ items: items })
      })
        .then(function (r) { return r.json(); })
        .then(renderCheckout)
        .catch(function () {
          document.getElementById("checkout-lines").innerHTML = "<tr><td colspan='6' style='padding:18px;color:#c0392b'>Error al cargar el resumen. Inténtalo de nuevo.</td></tr>";
        });
    }

    function allOrderText(data) {
      var d = results.dataset;
      var lines = ["Pedido Danbel · Cliente " + (d.customer || "sin código"), "Fecha: " + new Date().toLocaleDateString("es-ES"), ""];
      data.brands.forEach(function (brand) {
        lines.push("— " + brand.name + " —");
        brand.lines.forEach(function (l) {
          lines.push(l.type + " | " + l.code + " | EAN " + (l.ean || "sin EAN") + " | " + l.name + " | " + l.qty + " ud. × " + euro.format(l.net) + " = " + euro.format(l.total));
        });
        lines.push("");
      });
      var s = data.sums;
      lines.push("Productos (P): " + euro.format(s.products));
      lines.push("Testers (T) a PVP: " + euro.format(s.testers));
      if (s.free > 0) lines.push("Testers gratis: -" + euro.format(s.free));
      if (data.dto1 && s.d1 > 0) lines.push("Descuento 1 (" + data.dto1 + "%): -" + euro.format(s.d1));
      if (data.dto2 && s.d2 > 0) lines.push("Descuento 2 (" + data.dto2 + "%): -" + euro.format(s.d2));
      lines.push("TOTAL: " + euro.format(data.total));
      lines.push("IVA y transporte según las condiciones comerciales acordadas.");
      var noteText = notes.value.trim();
      if (noteText) lines.push("Observaciones: " + noteText);
      return lines.join("\n");
    }

    function closeCheckout() { checkout.hidden = true; }

    results.querySelector('[data-action="checkout"]').addEventListener("click", openCheckout);
    document.getElementById("checkout-back").addEventListener("click", closeCheckout);
    document.getElementById("checkout-back2").addEventListener("click", closeCheckout);
    document.getElementById("checkout-send").addEventListener("click", function () {
      var items = gatherAllItems();
      fetch("app/cart-summary.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ items: items })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          closeCheckout();
          var d = results.dataset;
          var subject = "Pedido Danbel · Cliente " + (d.customer || "sin código");
          window.location.href = "mailto:" + (d.orderEmail || "") + "?subject=" + encodeURIComponent(subject) + "&body=" + encodeURIComponent(allOrderText(data));
        })
        .catch(function () {
          closeCheckout();
          var d = results.dataset;
          var subject = "Pedido " + d.brand + " · Cliente " + (d.customer || "sin código");
          window.location.href = "mailto:" + (d.orderEmail || "") + "?subject=" + encodeURIComponent(subject) + "&body=" + encodeURIComponent(orderText());
        });
    });
  }

  update();
})();
