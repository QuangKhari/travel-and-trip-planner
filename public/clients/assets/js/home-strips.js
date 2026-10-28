/* D2 + D4: mũi tên cuộn dải, tắt khung tải khi ảnh xong, xem trước Planner. JS thuần, không phụ thuộc jQuery. */
(function () {
    "use strict";

    /* ---- dải ngang ---- */
    var reduce = matchMedia("(prefers-reduced-motion: reduce)");
    [].forEach.call(document.querySelectorAll(".hs"), function (sec) {
        var rail = sec.querySelector("[data-rail]");
        if (!rail) return;
        var btns = sec.querySelectorAll(".hs-arrow");
        function sync() {
            if (btns.length < 2) return;
            btns[0].disabled = rail.scrollLeft <= 4;
            btns[1].disabled = rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - 4;
        }
        [].forEach.call(btns, function (b) {
            b.addEventListener("click", function () {
                rail.scrollBy({ left: +b.dataset.dir * rail.clientWidth * 0.85, behavior: reduce.matches ? "auto" : "smooth" });
            });
        });
        rail.addEventListener("scroll", sync, { passive: true });
        addEventListener("resize", sync);
        sync();

        [].forEach.call(rail.querySelectorAll(".gx-card__img img"), function (img) {
            var box = img.parentNode;
            function done() { box.classList.add("is-loaded"); }
            if (img.complete) done();
            else {
                img.addEventListener("load", done, { once: true });
                img.addEventListener("error", done, { once: true });
            }
        });
    });

    /* ---- khối Planner (D4) ---- */
    var box = document.getElementById("hpl");
    if (!box) return;
    var form = box.querySelector("form"),
        out = document.getElementById("hpl-out"),
        api = box.dataset.api;
    if (box.dataset.hasPlanner === "1") return; /* có Planner thật: form gửi thẳng sang trang đó */

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function link(href, text, sub) {
        var a = el("a", null, text);
        if (/^https?:\/\//.test(href) || href.charAt(0) === "/") a.href = href;
        if (sub) a.appendChild(el("small", null, sub));
        return a;
    }
    function col(title, rows) {
        var c = el("div");
        c.appendChild(el("h4", null, title));
        var ul = el("ul");
        rows.forEach(function (r) {
            var li = el("li");
            li.appendChild(link(r.url, r.title, r.sub));
            ul.appendChild(li);
        });
        c.appendChild(ul);
        return c;
    }
    function show(d) {
        out.textContent = "";
        out.appendChild(el("h3", null, d.destination ? "Gợi ý cho " + d.destination.name : "Tháng " + d.month + " đang đẹp ở"));
        if (d.verdict && d.verdict.text) out.appendChild(el("p", "hpl-verdict is-" + d.verdict.level, d.verdict.text));
        if (d.verdict && d.verdict.url) {
            var more = el("a", "hpl-more", "Xem chi tiết tháng này →");
            more.href = d.verdict.url;
            out.appendChild(more);
        }
        var cols = el("div", "hpl-cols");
        if (d.itineraries.length)
            cols.appendChild(col("Lịch trình mẫu", d.itineraries.map(function (i) { return { url: i.url, title: i.title, sub: i.days + " ngày" }; })));
        if (d.tours.length)
            cols.appendChild(col("Tour hợp lịch", d.tours.map(function (t) { return { url: t.url, title: t.title, sub: "Khởi hành " + t.start + " · từ " + t.price }; })));
        if (d.suggestions.length)
            cols.appendChild(col("Điểm đến nên cân nhắc", d.suggestions.map(function (s) { return { url: s.url, title: s.name, sub: s.why }; })));
        if (cols.children.length) out.appendChild(cols);
        else out.appendChild(el("p", null, "Chưa có lịch trình hoặc tour phù hợp cho lựa chọn này."));
        out.appendChild(el("p", "hpl-note", "Đây là bản xem trước từ lịch trình mẫu và tour hiện có; trang lập kế hoạch đầy đủ sẽ sớm có."));
        out.hidden = false;
    }

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        var p = new URLSearchParams(new FormData(form));
        if (!p.get("diem-den")) p.delete("diem-den");
        fetch(api + "?" + p.toString(), { headers: { Accept: "application/json" } })
            .then(function (r) { if (!r.ok) throw 0; return r.json(); })
            .then(show)
            .catch(function () {
                out.textContent = "Không tải được gợi ý, bạn thử lại sau nhé.";
                out.hidden = false;
            });
    });
})();
