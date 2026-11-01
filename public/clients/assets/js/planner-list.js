/* "Chuyến đi của tôi" (/len-ke-hoach): lọc thẻ chuyến đi và biến biểu mẫu tạo chuyến thành 3 bước (Đi đâu? Khi nào? Đi cùng ai?).
 * Không có JavaScript thì biểu mẫu vẫn dùng được: các bước hiện cùng lúc, ô chọn điểm đến là một <select>. */
(function () {
    "use strict";
    var $ = function (id) { return document.getElementById(id); };

    /* ---------------- lọc thẻ ---------------- */
    var grid = $("tplGrid");
    document.querySelectorAll(".tpl-filter").forEach(function (b) {
        b.addEventListener("click", function () {
            var f = b.getAttribute("data-f"), shown = 0;
            document.querySelectorAll(".tpl-filter").forEach(function (x) {
                var on = x === b;
                x.classList.toggle("is-on", on);
                x.setAttribute("aria-selected", on ? "true" : "false");
            });
            if (grid) grid.querySelectorAll(".tpl-card").forEach(function (c) {
                var ok = f === "all" || c.getAttribute("data-phase") === f;
                c.hidden = !ok;
                if (ok) shown++;
            });
            if ($("tplNone")) $("tplNone").hidden = shown > 0;
        });
    });

    /* ---------------- tạo chuyến 3 bước ---------------- */
    var form = $("tplForm");
    if (!form) return;
    var box = $("tplNew"), steps = form.querySelectorAll(".tpl-fs"), cur = 1, titleTouched = !!$("tplTitle").value;
    var dests = {};
    document.querySelectorAll("#tplDests option").forEach(function (o) { dests[o.value.toLowerCase()] = { id: o.getAttribute("data-id"), name: o.value }; });
    box.classList.add("is-js");

    function destName() {
        var sel = $("tplDest");
        return sel.value ? sel.options[sel.selectedIndex].text : $("tplWhere").value.trim();
    }
    function autoTitle() {
        if (titleTouched) return;
        var n = destName(), d = parseInt($("tplDays").value, 10) || 0;
        $("tplTitle").value = n ? n + (d ? " " + d + " ngày" : "") : "";
    }
    function syncWhere() {
        var v = $("tplWhere").value.trim().toLowerCase(), hit = dests[v];
        $("tplDest").value = hit ? hit.id : "";
        $("tplWhereMsg").textContent = !v ? "Không bắt buộc. Chọn một điểm đến để Travela ưu tiên nội dung ở đó."
            : hit ? "Travela có cẩm nang cho " + hit.name + ": gợi ý, món ăn và tour ở đó sẽ được ưu tiên."
                : "“" + $("tplWhere").value.trim() + "” chưa có trên Travela. Vẫn lập được chuyến, bạn tự thêm địa điểm bằng tìm kiếm trên bản đồ.";
        document.querySelectorAll("#tplPopular .tp-chip").forEach(function (c) { c.classList.toggle("is-on", hit && c.getAttribute("data-dest") === String(hit.id)); });
        autoTitle();
    }
    function iso(d) { return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
    function syncDates(from) {
        var s = $("tplStart").value, e = $("tplEnd").value, n = parseInt($("tplDays").value, 10) || 1, max = parseInt($("tplDays").max, 10) || 14;
        $("tplEnd").min = s || "";
        if (from === "days" && s) {
            var d = new Date(s + "T00:00:00");
            d.setDate(d.getDate() + Math.max(1, Math.min(max, n)) - 1);
            $("tplEnd").value = iso(d);
        } else if (s && e) {
            var diff = Math.round((new Date(e + "T00:00:00") - new Date(s + "T00:00:00")) / 864e5) + 1;
            if (diff < 1) { $("tplEnd").value = s; diff = 1; }
            if (diff > max) { diff = max; syncDates("days"); }
            $("tplDays").value = String(diff);
        } else if (s && from === "start") {
            syncDates("days");
        }
        var msg = $("tplWhenMsg");
        n = parseInt($("tplDays").value, 10) || 1;
        msg.textContent = s ? n + " ngày, từ " + s.split("-").reverse().slice(0, 2).join("/") + (n > 1 ? " đến " + $("tplEnd").value.split("-").reverse().slice(0, 2).join("/") : "") + "."
            : "Chưa chắc ngày? Để trống ngày đi và chỉ chọn số ngày, có thể thêm sau.";
        autoTitle();
    }
    function show(n) {
        cur = n;
        steps.forEach(function (fs) { fs.hidden = +fs.getAttribute("data-step") !== n; });
        $("tplBack").hidden = n === 1;
        $("tplNext").hidden = n === 3;
        $("tplSubmit").hidden = n !== 3;
        $("tplStepLbl").textContent = "Bước " + n + "/3";
        box.querySelectorAll(".tpl-prog span").forEach(function (s, i) { s.classList.toggle("is-on", i < n); });
        var f = steps[n - 1].querySelector("input:not([type=hidden]):not([type=checkbox])");
        if (f && box.getAttribute("data-collapsed") !== "1") f.focus();
    }
    $("tplNext").addEventListener("click", function () {
        if (cur === 2) {
            var d = parseInt($("tplDays").value, 10);
            if (!d || d < 1 || d > (parseInt($("tplDays").max, 10) || 14)) { $("tplDays").focus(); $("tplWhenMsg").textContent = "Số ngày từ 1 đến " + $("tplDays").max + "."; return; }
        }
        show(Math.min(3, cur + 1));
    });
    $("tplBack").addEventListener("click", function () { show(Math.max(1, cur - 1)); });
    form.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && cur < 3 && e.target.tagName === "INPUT") { e.preventDefault(); $("tplNext").click(); }
    });
    form.addEventListener("submit", function (e) {
        autoTitle();
        if (!$("tplTitle").value.trim()) {
            e.preventDefault();
            show(3);
            $("tplTitle").focus();
            $("tplTitle").setCustomValidity("Hãy đặt tên cho chuyến đi.");
            $("tplTitle").reportValidity();
        }
    });
    $("tplTitle").addEventListener("input", function () { titleTouched = !!this.value; this.setCustomValidity(""); });
    $("tplWhere").addEventListener("input", syncWhere);
    $("tplDest").addEventListener("change", function () { $("tplWhere").value = this.value ? this.options[this.selectedIndex].text : ""; syncWhere(); });
    $("tplStart").addEventListener("change", function () { syncDates("start"); });
    $("tplEnd").addEventListener("change", function () { syncDates("end"); });
    $("tplDays").addEventListener("input", function () { syncDates("days"); });
    document.querySelectorAll("#tplPopular .tp-chip").forEach(function (c) {
        c.addEventListener("click", function () { $("tplWhere").value = c.textContent; syncWhere(); show(2); });
    });
    document.querySelectorAll(".tpl-stepper button").forEach(function (b) {
        b.addEventListener("click", function () {
            var i = $("tplGroup"), v = (parseInt(i.value, 10) || 1) + parseInt(b.getAttribute("data-d"), 10);
            i.value = String(Math.max(1, Math.min(50, v)));
        });
    });
    // có chuyến rồi: thu gọn biểu mẫu, mở khi bấm "Chuyến đi mới"
    function expand() { box.removeAttribute("data-collapsed"); show(cur); }
    $("tplNewBtn").addEventListener("click", function (e) { e.preventDefault(); expand(); box.scrollIntoView({ behavior: "smooth", block: "start" }); });
    box.addEventListener("click", function () { if (box.getAttribute("data-collapsed") === "1") expand(); });
    if ($("tplDest").value) $("tplWhere").value = $("tplDest").options[$("tplDest").selectedIndex].text;
    syncWhere();
    syncDates();
    show(1);
    if (location.hash === "#tplNew") expand();
})();
