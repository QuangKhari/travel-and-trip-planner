/* So sánh hai phương án (đi chậm / đi nhiều) cho CÙNG yêu cầu trên form "Để Travela đề xuất": số điểm, giờ kết thúc, quãng đường,
 * chi phí đã biết; chọn một thì đặt nhịp đi tương ứng và mở màn xem trước. POST /len-ke-hoach/so-sanh (không ghi gì). */
(function () {
    "use strict";
    var btn = document.getElementById("sgCompare"), box = document.getElementById("sgCmp"), form = document.getElementById("sgForm");
    if (!btn || !box || !form) return;
    function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text != null) x.textContent = text; return x; }
    function money(v) { return v ? Math.round(v).toLocaleString("vi-VN") + "đ" : "chưa rõ"; }
    function body() {
        var o = {};
        new FormData(form).forEach(function (v, k) {
            if (/\[\]$/.test(k)) { k = k.slice(0, -2); (o[k] = o[k] || []).push(v); } else if (v !== "") o[k] = v;
        });
        return o;
    }
    btn.addEventListener("click", function () {
        btn.disabled = true;
        box.hidden = false;
        box.textContent = "";
        box.appendChild(el("p", "tp-muted tp-small", "Đang xếp hai phương án…"));
        fetch(btn.dataset.url, { method: "POST", credentials: "same-origin", headers: { "Accept": "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": btn.dataset.csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body()) })
            .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw j; return j; }); })
            .then(function (j) {
                box.textContent = "";
                var grid = el("div", "tpf-cmp__grid");
                j.options.forEach(function (o) {
                    var c = el("article", "tpf-cmp__c");
                    c.appendChild(el("h4", null, o.label));
                    var dl = el("dl");
                    [["Điểm tham quan", o.activities + " (" + o.perDay + "/ngày)"], ["Ngày dày nhất", o.busiest + " điểm"], ["Kết thúc ngày TB", o.avgEnd || "—"],
                        ["Quãng đường", o.km + " km"], ["Chi phí đã biết", money(o.cost)], ["Để dành (chưa xếp)", o.unplaced + " nơi"]].forEach(function (r) {
                        var d = el("div"); d.appendChild(el("dt", null, r[0])); d.appendChild(el("dd", null, r[1])); dl.appendChild(d);
                    });
                    c.appendChild(dl);
                    if (o.sample.length) c.appendChild(el("p", "tp-small tp-muted", "Ngày 1: " + o.sample.join(" · ")));
                    var pick = el("button", "tp-btn tpf-cmp__pick", "Chọn " + o.label.toLowerCase()); pick.type = "button";
                    pick.addEventListener("click", function () {
                        var r = form.querySelector('input[name="pace"][value="' + o.pace + '"]');
                        if (r) { r.checked = true; r.dispatchEvent(new Event("change", { bubbles: true })); }
                        form.requestSubmit ? form.requestSubmit() : form.submit();
                    });
                    c.appendChild(pick);
                    grid.appendChild(c);
                });
                box.appendChild(grid);
                var ul = el("ul", "tpf-cmp__why");
                j.why.forEach(function (w) { ul.appendChild(el("li", null, w)); });
                box.appendChild(ul);
            })
            .catch(function (j) { box.textContent = ""; box.appendChild(el("p", "tpf-err", (j && !j.errors && j.message) || "Chưa so sánh được. Hãy chọn nơi đến (và ngày đi nếu có) trước.")); })
            .then(function () { btn.disabled = false; });
    });
})();

/* Chuyến nhiều thành phố: thêm chặng; có chặng thì form gửi POST tới /len-ke-hoach/tao-nhieu-noi thay vì xem trước một điểm đến. */
(function () {
    "use strict";
    var box = document.getElementById("sgMulti"), form = document.getElementById("sgForm");
    if (!box || !form) return;
    var data = {};
    try { data = JSON.parse(document.getElementById("sgData").textContent); } catch (e) { data = {}; }
    var dests = (data.dests || []).slice().sort(function (a, b) { return (b.ready - a.ready) || a.name.localeCompare(b.name, "vi"); });
    var list = document.getElementById("sgLegs");
    function el(tag, cls, text) { var x = document.createElement(tag); if (cls) x.className = cls; if (text != null) x.textContent = text; return x; }
    function addLeg() {
        if (list.children.length >= 4) return;
        var li = el("li", "tpf-leg");
        var sel = el("select"); sel.setAttribute("aria-label", "Thành phố tiếp theo");
        var o0 = el("option", null, "— chọn thành phố —"); o0.value = ""; sel.appendChild(o0);
        dests.forEach(function (d) { var o = el("option", null, d.name + (d.ready ? "" : " (đề xuất mở rộng)")); o.value = d.id; sel.appendChild(o); });
        var days = el("input"); days.type = "number"; days.min = 1; days.max = 7; days.value = 2; days.setAttribute("aria-label", "Số ngày ở thành phố này");
        var rm = el("button", "tp-mini", "Bỏ"); rm.type = "button"; rm.addEventListener("click", function () { li.remove(); sync(); });
        li.appendChild(sel); li.appendChild(days); li.appendChild(el("span", "tpf-small", "ngày")); li.appendChild(rm);
        list.appendChild(li);
        sync();
    }
    function sync() {
        var cta = form.querySelector(".tpf-cta");
        if (cta) cta.firstChild.textContent = list.children.length ? "Tạo chuyến nhiều thành phố " : "Xem trước lịch trình ";
    }
    document.getElementById("sgAddLeg").addEventListener("click", addLeg);
    form.addEventListener("submit", function (e) {
        if (!list.children.length) return;
        var legs = [{ id: (document.getElementById("sgDestId") || {}).value, days: (document.getElementById("sgDays") || {}).value }];
        Array.prototype.forEach.call(list.children, function (li) { legs.push({ id: li.querySelector("select").value, days: li.querySelector("input").value }); });
        if (legs.some(function (l) { return !l.id; })) { e.preventDefault(); alert("Hãy chọn đủ thành phố cho mọi chặng (thành phố đầu chọn ở ô phía trên)."); return; }
        form.method = "POST";
        form.action = box.dataset.url;
        form.querySelectorAll("input[data-multi]").forEach(function (x) { x.remove(); });
        var put = function (n, v) { var i = el("input"); i.type = "hidden"; i.name = n; i.value = v; i.setAttribute("data-multi", "1"); form.appendChild(i); };
        put("_token", box.dataset.csrf);
        put("hopMode", document.getElementById("sgHop").value);
        legs.forEach(function (l, k) { put("legs[" + k + "][destinationId]", l.id); put("legs[" + k + "][days]", l.days); });
        // các ô của chế độ một điểm đến không gửi kèm (tránh lỗi kiểm tra)
        ["destinationId", "placeLat", "placeLng", "placeName", "days", "month"].forEach(function (n) { var f = form.querySelector('[name="' + n + '"]'); if (f) f.disabled = true; });
    });
})();
