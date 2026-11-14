/* Chi tiêu nhóm (#tpExp): đọc / ghi qua TripExpenseController, mọi phản hồi trả lại toàn bộ tóm tắt -> vẽ lại. */
(function () {
    "use strict";
    var root = document.getElementById("tpExp");
    if (!root) return;
    var data = document.getElementById("tpData");
    var csrf = data ? (JSON.parse(data.textContent).csrf || "") : "";
    var body = root.querySelector(".tge__body"), addBtn = document.getElementById("tpExpAdd");
    var S = null, editing = null;
    var ICON = { food: "fa-utensils", stay: "fa-bed", move: "fa-bus", ticket: "fa-ticket-alt", shop: "fa-shopping-bag", other: "fa-receipt" };

    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
    function money(n) { return (n < 0 ? "−" : "") + Math.abs(Math.round(n)).toLocaleString("vi-VN") + "đ"; }
    function name(who) { return (S && S.names[who]) || "?"; }
    function req(url, method, payload) {
        return fetch(url, { method: method || "GET", credentials: "same-origin",
            headers: { "Accept": "application/json", "Content-Type": "application/json", "X-CSRF-TOKEN": csrf, "X-Requested-With": "XMLHttpRequest" },
            body: payload ? JSON.stringify(payload) : undefined })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { j.__ok = r.ok; return j; }); });
    }
    function flash(t, bad) {
        var m = root.querySelector(".tge__msg");
        if (!m) { m = el("p", "tge__msg"); m.setAttribute("role", "status"); root.insertBefore(m, body); }
        m.textContent = t || ""; m.classList.toggle("is-bad", !!bad);
    }
    function apply(j) {
        if (j && j.people) { S = j; render(); }
        if (j && j.message) flash(j.message, !j.__ok);
        else if (j && !j.__ok) { var e = j.errors ? j.errors[Object.keys(j.errors)[0]][0] : "Chưa lưu được."; flash(e, true); }
    }
    function load() { req(root.dataset.url).then(apply).catch(function () { body.textContent = "Chưa tải được chi tiêu nhóm."; }); }

    function render() {
        body.textContent = "";
        addBtn.hidden = !S.canEdit;
        // tổng + theo người
        var top = el("div", "tge__sum");
        var tot = el("div", "tge__total"); tot.appendChild(el("small", null, "Tổng đã chi")); tot.appendChild(el("b", null, money(S.total))); top.appendChild(tot);
        var cats = el("ul", "tge__cats");
        Object.keys(S.byCategory).sort(function (a, b) { return S.byCategory[b] - S.byCategory[a]; }).forEach(function (c) {
            var li = el("li"); var i = el("i", "fas " + (ICON[c] || ICON.other)); i.setAttribute("aria-hidden", "true"); li.appendChild(i);
            li.appendChild(el("span", null, (S.categories[c] || c) + " " + money(S.byCategory[c]))); cats.appendChild(li);
        });
        if (cats.children.length) top.appendChild(cats);
        body.appendChild(top);

        var tbl = el("table", "tge__people");
        tbl.innerHTML = "<caption class='tge__cap'>Mỗi người</caption><thead><tr><th scope='col'>Người</th><th scope='col' class='num'>Đã trả</th><th scope='col' class='num'>Phần của mình</th><th scope='col' class='num'>Chênh</th></tr></thead>";
        var tb = el("tbody");
        Object.keys(S.names).forEach(function (w) {
            var tr = el("tr"); var b = S.balance[w] || 0;
            var th = el("th", null, name(w) + (w === S.me ? " (bạn)" : "")); th.scope = "row"; tr.appendChild(th);
            tr.appendChild(el("td", "num", money(S.paid[w] || 0)));
            tr.appendChild(el("td", "num", money(S.owe[w] || 0)));
            tr.appendChild(el("td", "num " + (b > 0 ? "is-plus" : b < 0 ? "is-minus" : ""), b === 0 ? "0đ" : (b > 0 ? "+" : "") + money(b)));
            tb.appendChild(tr);
        });
        tbl.appendChild(tb);
        var wrap = el("div", "tge__tw"); wrap.appendChild(tbl); body.appendChild(wrap);

        var st = el("div", "tge__settle");
        st.appendChild(el("h3", null, "Để cả nhóm hòa nhau"));
        if (!S.settle.length) st.appendChild(el("p", "tp-muted tp-small", S.total ? "Đã hòa, không ai nợ ai." : "Chưa có khoản chi nào."));
        else {
            var ul = el("ul");
            S.settle.forEach(function (x) {
                var li = el("li");
                li.appendChild(el("b", null, name(x.from))); li.appendChild(document.createTextNode(" trả ")); li.appendChild(el("b", null, name(x.to)));
                li.appendChild(el("span", "tge__amt", money(x.amount))); ul.appendChild(li);
            });
            st.appendChild(ul);
        }
        body.appendChild(st);

        // danh sách khoản chi
        var h = el("h3", "tge__lh", "Các khoản đã chi (" + S.expenses.length + ")"); body.appendChild(h);
        if (!S.expenses.length) {
            var e = el("div", "tge__empty");
            e.appendChild(el("p", null, S.canEdit ? "Chưa có khoản chi. Ghi ngay khi trả tiền để cuối chuyến khỏi phải nhớ lại." : "Chưa có khoản chi."));
            body.appendChild(e);
        } else {
            var list = el("ul", "tge__list");
            S.expenses.forEach(function (x) {
                var li = el("li", "tge__it");
                var ic = el("span", "tge__ic"); var i = el("i", "fas " + (ICON[x.category] || ICON.other)); i.setAttribute("aria-hidden", "true"); ic.appendChild(i); li.appendChild(ic);
                var m = el("div", "tge__main");
                m.appendChild(el("b", null, x.title));
                var n = Object.keys(x.shares).length;
                m.appendChild(el("small", null, name(x.paidBy) + " trả · chia " + (x.splitMode === "custom" ? "theo số tiền riêng" : "đều") + " cho " + n + " người" + (x.spentOn ? " · " + x.spentOn.split("-").reverse().slice(0, 2).join("/") : "")));
                if (x.note) m.appendChild(el("small", "tge__note", x.note));
                li.appendChild(m);
                li.appendChild(el("span", "tge__amt", money(x.amount)));
                if (S.canEdit) {
                    var acts = el("span", "tge__acts");
                    var eb = el("button", "tp-mini", "Sửa"); eb.type = "button"; eb.setAttribute("aria-label", "Sửa " + x.title);
                    eb.addEventListener("click", function () { openForm(x); }); acts.appendChild(eb);
                    if (S.isOwner || x.createdBy === +S.me.slice(2)) {
                        var db = el("button", "tp-mini tp-mini--danger", "Xóa"); db.type = "button"; db.setAttribute("aria-label", "Xóa " + x.title);
                        db.addEventListener("click", function () {
                            if (!window.confirm("Xóa khoản \"" + x.title + "\"?")) return;
                            req(root.dataset.url + "/" + x.id + "/xoa", "POST", {}).then(apply);
                        });
                        acts.appendChild(db);
                    }
                    li.appendChild(acts);
                }
                list.appendChild(li);
            });
            body.appendChild(list);
        }
        if (S.canEdit) body.appendChild(guestBox());
        if (editing !== null) openForm(editing === "new" ? null : editing);
    }

    function guestBox() {
        var d = el("details", "tge__guests");
        d.appendChild(el("summary", null, "Người đi cùng chưa có tài khoản (" + S.people.filter(function (p) { return p.kind === "guest"; }).length + ")"));
        var ul = el("ul");
        S.people.filter(function (p) { return p.kind === "guest"; }).forEach(function (p) {
            var li = el("li", null, p.name + " ");
            var x = el("button", "tp-mini", "Gỡ"); x.type = "button"; x.setAttribute("aria-label", "Gỡ " + p.name);
            x.addEventListener("click", function () { req(root.dataset.guest + "/" + p.who.slice(2) + "/xoa", "POST", {}).then(apply); });
            li.appendChild(x); ul.appendChild(li);
        });
        d.appendChild(ul);
        var f = el("form", "tge__gform");
        var inp = el("input"); inp.maxLength = 60; inp.placeholder = "Tên, ví dụ: Mẹ, anh Tú"; inp.setAttribute("aria-label", "Tên người đi cùng"); inp.required = true;
        var b = el("button", "tp-btn tp-btn--ghost", "Thêm"); b.type = "submit";
        f.appendChild(inp); f.appendChild(b);
        f.addEventListener("submit", function (e) { e.preventDefault(); req(root.dataset.guest, "POST", { name: inp.value }).then(apply); });
        d.appendChild(f);
        d.open = S.people.length < 2;
        return d;
    }

    /* ---- biểu mẫu thêm / sửa ---- */
    function openForm(x) {
        editing = x || "new";
        var old = root.querySelector(".tge__form"); if (old) old.remove();
        var f = el("form", "tge__form");
        f.setAttribute("aria-label", x ? "Sửa khoản chi" : "Thêm khoản chi");
        f.innerHTML = "<h3>" + (x ? "Sửa khoản chi" : "Thêm khoản chi") + "</h3>" +
            "<div class='tge__grid'>" +
            "<label class='tge__wide'>Khoản chi<input name='title' maxlength='120' required placeholder='Ví dụ: Lẩu gà lá é tối ngày 1'></label>" +
            "<label>Số tiền (đ)<input name='amount' inputmode='numeric' required placeholder='450000'></label>" +
            "<label>Loại<select name='category' class='no-nice'></select></label>" +
            "<label>Ngày<input type='date' name='spentOn'></label>" +
            "<label>Ai trả<select name='paidBy' class='no-nice'></select></label>" +
            "</div>" +
            "<fieldset class='tge__split'><legend>Chia cho</legend>" +
            "<div class='tge__mode' role='radiogroup' aria-label='Cách chia'><label><input type='radio' name='splitMode' value='equal' checked> Chia đều</label><label><input type='radio' name='splitMode' value='custom'> Số tiền riêng từng người</label></div>" +
            "<div class='tge__who'></div><p class='tge__left tp-small'></p></fieldset>" +
            "<label class='tge__wide'>Ghi chú<input name='note' maxlength='255' placeholder='Không bắt buộc'></label>" +
            "<div class='tge__fact'><button type='submit' class='tp-btn'>" + (x ? "Lưu thay đổi" : "Thêm khoản chi") + "</button><button type='button' class='tp-btn tp-btn--ghost tge__cancel'>Hủy</button></div>";
        var cat = f.querySelector("[name=category]");
        Object.keys(S.categories).forEach(function (k) { var o = el("option", null, S.categories[k]); o.value = k; cat.appendChild(o); });
        var pb = f.querySelector("[name=paidBy]");
        S.people.forEach(function (p) { var o = el("option", null, p.name + (p.who === S.me ? " (bạn)" : "")); o.value = p.who; pb.appendChild(o); });
        var who = f.querySelector(".tge__who"), left = f.querySelector(".tge__left");
        var amt = f.querySelector("[name=amount]");
        function amountVal() { return parseInt(String(amt.value).replace(/\D/g, ""), 10) || 0; }
        function mode() { return f.querySelector("[name=splitMode]:checked").value; }
        function drawWho() {
            who.textContent = "";
            S.people.forEach(function (p) {
                var row = el("label", "tge__wrow");
                if (mode() === "equal") {
                    var c = el("input"); c.type = "checkbox"; c.value = p.who;
                    c.checked = x ? (x.shares[p.who] !== undefined) : true;
                    row.appendChild(c); row.appendChild(el("span", null, p.name));
                } else {
                    row.appendChild(el("span", null, p.name));
                    var n = el("input"); n.inputMode = "numeric"; n.dataset.who = p.who; n.placeholder = "0"; n.setAttribute("aria-label", "Phần của " + p.name);
                    if (x && x.splitMode === "custom" && x.shares[p.who]) n.value = x.shares[p.who];
                    row.appendChild(n);
                }
                who.appendChild(row);
            });
            hint();
        }
        function hint() {
            var a = amountVal();
            if (mode() === "equal") {
                var k = who.querySelectorAll("input:checked").length;
                left.textContent = k ? "Mỗi người khoảng " + money(Math.floor(a / k)) + "." : "Chọn ít nhất một người.";
            } else {
                var s = 0; who.querySelectorAll("input").forEach(function (i) { s += parseInt(String(i.value).replace(/\D/g, ""), 10) || 0; });
                left.textContent = s === a ? "Khớp số tiền." : "Còn " + money(a - s) + " chưa chia.";
                left.classList.toggle("is-bad", s !== a);
            }
        }
        f.querySelectorAll("[name=splitMode]").forEach(function (r) { r.addEventListener("change", drawWho); });
        who.addEventListener("input", hint); amt.addEventListener("input", hint);
        if (x) {
            f.elements["title"].value = x.title; amt.value = x.amount; cat.value = x.category; pb.value = x.paidBy;
            f.elements["spentOn"].value = x.spentOn || ""; f.elements["note"].value = x.note || "";
            if (x.splitMode === "custom") f.querySelector("[name=splitMode][value=custom]").checked = true;
        } else {
            pb.value = S.me in S.names ? S.me : (S.people[0] || {}).who;
            var d0 = new Date(), today = d0.getFullYear() + "-" + String(d0.getMonth() + 1).padStart(2, "0") + "-" + String(d0.getDate()).padStart(2, "0");   // ngày theo giờ máy, không theo UTC
            f.elements["spentOn"].value = today;
        }
        drawWho();
        f.querySelector(".tge__cancel").addEventListener("click", function () { editing = null; f.remove(); });
        f.addEventListener("submit", function (e) {
            e.preventDefault();
            var p = { title: f.elements["title"].value, amount: amountVal(), category: cat.value, paidBy: pb.value, splitMode: mode(), spentOn: f.elements["spentOn"].value || null, note: f.elements["note"].value || null };
            if (p.splitMode === "equal") p.who = Array.prototype.map.call(who.querySelectorAll("input:checked"), function (c) { return c.value; });
            else { p.shares = {}; who.querySelectorAll("input").forEach(function (i) { var v = parseInt(String(i.value).replace(/\D/g, ""), 10) || 0; if (v) p.shares[i.dataset.who] = v; }); }
            var btn = f.querySelector("[type=submit]"); btn.disabled = true;
            req(root.dataset.url + (x ? "/" + x.id : ""), "POST", p).then(function (j) { if (j.__ok) editing = null; apply(j); }).then(function () { btn.disabled = false; });
        });
        body.insertBefore(f, body.firstChild);
        f.elements["title"].focus();
    }
    addBtn.addEventListener("click", function () { openForm(null); });

    // tải khi mở tab Chi phí lần đầu (đỡ một yêu cầu cho người không xem tab này)
    var loaded = false;
    function maybeLoad() { if (!loaded && !root.closest("[hidden]")) { loaded = true; load(); } }
    document.addEventListener("click", function (e) { if (e.target.closest('[data-tab="cost"], [data-top="cost"]')) setTimeout(maybeLoad, 50); });
    maybeLoad();
})();
