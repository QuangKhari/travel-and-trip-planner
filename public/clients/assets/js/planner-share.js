/* Hộp chia sẻ chuyến đi (#tpMembers): hai tab "Cùng lên kế hoạch" / "Chỉ xem", liên kết mời, sao chép, mạng xã hội, quyền riêng tư.
 * Mời bằng email, danh sách thành viên, bật/tắt liên kết chỉ xem do planner.js lo (giữ nguyên id: tpInvForm, tpInvRole, tpMemList, tpShareBtn). */
(function () {
    "use strict";
    var box = document.getElementById("tpMembers");
    if (!box) return;
    var $ = function (id) { return document.getElementById(id); };
    var data = document.getElementById("tpData");
    var csrf = data ? (JSON.parse(data.textContent).csrf || "") : "";
    var msg = $("tpInvMsg");
    function say(t, bad) { if (msg) { msg.textContent = t; msg.classList.toggle("is-bad", !!bad); } }
    function post(url, body) {
        return fetch(url, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json", "Accept": "application/json", "X-CSRF-TOKEN": csrf, "X-Requested-With": "XMLHttpRequest" }, body: JSON.stringify(body) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok) throw j; return j; }); });
    }

    /* ---- tab ---- */
    var tabs = box.querySelectorAll(".tps__tab");
    function setTab(name) {
        tabs.forEach(function (t) {
            var on = t.dataset.tab === name;
            t.classList.toggle("is-on", on);
            t.setAttribute("aria-selected", on ? "true" : "false");
            t.tabIndex = on ? 0 : -1;
            var p = $(t.getAttribute("aria-controls"));
            if (p) p.hidden = !on;
        });
        say("");
        if ($("tpInvRole")) $("tpInvRole").value = name === "view" ? "view" : "edit";
        if ($("tpsInvLabel")) $("tpsInvLabel").textContent = name === "view" ? "Mời bằng email (quyền chỉ xem, cần tài khoản Travela)" : "Mời bằng email (quyền chỉnh sửa)";
    }
    tabs.forEach(function (t, i) {
        t.addEventListener("click", function () { setTab(t.dataset.tab); });
        t.addEventListener("keydown", function (e) {
            if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") return;
            var n = tabs[(i + (e.key === "ArrowRight" ? 1 : tabs.length - 1)) % tabs.length];
            setTab(n.dataset.tab); n.focus();
        });
    });

    /* ---- sao chép ---- */
    box.addEventListener("click", function (e) {
        var b = e.target.closest(".tps__copy");
        if (!b) return;
        var inp = $(b.dataset.copy);
        if (!inp || !inp.value) return;
        var done = function () { var t = b.textContent; b.textContent = "Đã chép"; setTimeout(function () { b.textContent = t; }, 1600); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(inp.value).then(done, function () { inp.select(); document.execCommand("copy"); done(); });
        else { inp.select(); document.execCommand("copy"); done(); }
    });

    /* ---- liên kết mời ---- */
    var joinUrl = box.dataset.join;
    function setJoin(url) {
        $("tpsJoinUrl").value = url || "";
        $("tpsJoinBox").classList.toggle("is-off", !url);
        $("tpsJoinOn").checked = !!url;
        $("tpsJoinRegen").disabled = !url;
        $("tpsJoinBox").querySelector(".tps__copy").disabled = !url;
    }
    if (joinUrl && $("tpsJoinOn")) {
        $("tpsJoinOn").addEventListener("change", function () {
            var on = $("tpsJoinOn").checked;
            post(joinUrl, { on: on, role: "edit" }).then(function (j) { setJoin(j.url); say(on ? "Đã bật liên kết mời. Gửi cho bạn bè để cùng lên kế hoạch." : "Đã tắt liên kết mời."); })
                .catch(function (j) { $("tpsJoinOn").checked = !on; say((j && j.message) || "Không đổi được liên kết mời.", true); });
        });
        $("tpsJoinRegen").addEventListener("click", function () {
            if (!window.confirm("Tạo liên kết mời mới? Liên kết cũ sẽ không dùng được nữa (người đã vào nhóm vẫn giữ nguyên).")) return;
            post(joinUrl, { on: true, role: "edit", regen: true }).then(function (j) { setJoin(j.url); say("Đã tạo liên kết mời mới."); })
                .catch(function () { say("Không tạo được liên kết mới.", true); });
        });
    }

    /* ---- liên kết chỉ xem + mạng xã hội ---- */
    var title = box.dataset.title || "Chuyến đi";
    function setSocial(url) {
        var s = $("tpsSocial");
        if (!s) return;
        s.hidden = !url;
        $("tpsViewBox").classList.toggle("is-off", !url);
        $("tpsViewBox").querySelector(".tps__copy").disabled = !url;
        if (!url) return;
        var u = encodeURIComponent(url), t = encodeURIComponent(title + " — lịch trình trên Travela");
        var map = {
            facebook: "https://www.facebook.com/sharer/sharer.php?u=" + u,
            x: "https://twitter.com/intent/tweet?url=" + u + "&text=" + t,
            email: "mailto:?subject=" + t + "&body=" + encodeURIComponent("Mình gửi lịch trình chuyến đi: " + url)
        };
        Object.keys(map).forEach(function (k) { var a = s.querySelector('[data-soc="' + k + '"]'); if (a) a.href = map[k]; });
        var nat = s.querySelector('[data-soc="native"]');
        if (nat && navigator.share) nat.hidden = false;   // điện thoại: Zalo, Messenger... qua bảng chia sẻ của máy
    }
    setSocial($("tpShareUrl") ? $("tpShareUrl").value : "");
    document.addEventListener("tp:share", function (e) { setSocial(e.detail.url); });
    if ($("tpsSocial")) $("tpsSocial").addEventListener("click", function (e) {
        var b = e.target.closest("[data-soc]");
        if (!b) return;
        if (b.dataset.soc === "native") { e.preventDefault(); navigator.share({ title: title, url: $("tpShareUrl").value }).catch(function () {}); }
        if (b.dataset.soc === "pdf") { e.preventDefault(); var p = $("tpPdf"); if (p) p.click(); }
    });

    /* ---- quyền riêng tư của bản chỉ xem ---- */
    var privUrl = box.dataset.privacy;
    box.querySelectorAll("[data-privacy]").forEach(function (c) {
        c.addEventListener("change", function () {
            if (!privUrl) return;
            var body = {}; body[c.dataset.privacy] = c.checked;
            post(privUrl, body).then(function () { say("Đã lưu: bản chỉ xem " + (c.checked ? "hiện " : "ẩn ") + c.closest("label").querySelector("span").firstChild.textContent.trim().toLowerCase() + "."); })
                .catch(function () { c.checked = !c.checked; say("Chưa lưu được, thử lại nhé.", true); });
        });
    });
})();
