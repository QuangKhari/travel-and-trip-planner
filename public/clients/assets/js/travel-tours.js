/* Trang /tours — lọc, sắp xếp, tìm kiếm bằng AJAX (JS thuần, không cần jQuery).
   Máy chủ: GET /filter-tours  →  JSON { html, total, page, lastPage }.
   Trạng thái đồng bộ lên URL (?keyword=&from=&to=&guests=&cat=&dest=&domain=&days=&rating=&min=&max=&sort=&page=)
   nên copy link là chia sẻ được đúng kết quả đang xem. */
(function () {
    "use strict";
    var cfg = window.TV_TOURS;
    if (!cfg) return;

    var $ = function (s, r) {
        return (r || document).querySelector(s);
    };
    var $$ = function (s, r) {
        return Array.prototype.slice.call((r || document).querySelectorAll(s));
    };

    var results = $("#tvResults"),
        countEl = $("#tvCount"),
        activeEl = $("#tvActive");
    var form = $("#tvSearchForm"),
        kwInput = $("#tvKeyword"),
        kwClear = $("#tvKeywordClear");
    var fromIn = $("#tvFrom"),
        toIn = $("#tvTo"),
        domainSel = $("#tvDomain"),
        sortSel = $("#tvSort"),
        guestOut = $("#tvGuests");
    var minIn = $("#tvMin"),
        maxIn = $("#tvMax"),
        fill = $("#tvRangeFill");
    var minLbl = $("#tvMinLabel"),
        maxLbl = $("#tvMaxLabel");
    var filterBox = $("#tvFilter"),
        overlay = $("#tvOverlay");

    var DEFAULT = {
        from: "",
        to: "",
        keyword: "",
        guests: 0,
        cat: "",
        dest: "",
        domain: "",
        days: "",
        rating: 0,
        min: cfg.min,
        max: cfg.max,
        sort: "new",
        page: 1,
    };
    var state = Object.assign({}, DEFAULT, cfg.state || {});
    ["rating", "guests", "min", "max", "page"].forEach(function (k) {
        state[k] = Number(state[k]) || 0;
    });
    if (!state.min) state.min = cfg.min;
    if (!state.max) state.max = cfg.max;
    if (!state.page) state.page = 1;

    var SORT_LABEL = {
        new: "Mới nhất",
        old: "Cũ nhất",
        popular: "Phổ biến nhất",
        reviews: "Nhiều đánh giá",
        rating: "Đánh giá cao",
        price_asc: "Giá thấp → cao",
        price_desc: "Giá cao → thấp",
        soon: "Khởi hành sớm",
    };
    var LABELS = {
        domain: { b: "Miền Bắc", t: "Miền Trung", n: "Miền Nam" },
        days: {
            day: "Trong ngày",
            short: "2–3 ngày",
            mid: "4–5 ngày",
            long: "6 ngày trở lên",
        },
    };
    var fmtDate = function (iso) {
        var p = String(iso).split("-");
        return p.length === 3 ? p[2] + "/" + p[1] + "/" + p[0] : iso;
    };
    var money = function (n) {
        return Number(n).toLocaleString("vi-VN") + "đ";
    };
    var esc = function (s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            }[c];
        });
    };

    /* ---------- query string ---------- */
    function toQuery(withPage) {
        var p = new URLSearchParams();
        if (state.keyword) p.set("keyword", state.keyword);
        if (state.from) p.set("from", state.from);
        if (state.to) p.set("to", state.to);
        if (state.guests) p.set("guests", state.guests);
        if (state.cat) p.set("cat", state.cat);
        if (state.dest) p.set("dest", state.dest);
        if (state.domain) p.set("domain", state.domain);
        if (state.days) p.set("days", state.days);
        if (state.rating) p.set("rating", state.rating);
        if (state.min !== cfg.min) p.set("min", state.min);
        if (state.max !== cfg.max) p.set("max", state.max);
        if (state.sort !== "new") p.set("sort", state.sort);
        if (withPage && state.page > 1) p.set("page", state.page);
        return p.toString();
    }

    /* ---------- vẽ lại giao diện từ state ---------- */
    function paint() {
        $$("[data-filter]").forEach(function (b) {
            var key = b.dataset.filter,
                on =
                    String(state[key]) === b.dataset.value &&
                    state[key] !== "" &&
                    state[key] !== 0;
            b.classList.toggle("is-on", on);
            b.setAttribute("aria-pressed", on ? "true" : "false");
        });
        // chip "Tất cả" sáng khi chưa chọn cảm hứng nào
        var all = $("[data-action='clear-cat']");
        if (all) all.classList.toggle("is-on", !state.cat);

        // mức giá nhanh
        $$("[data-price-min]").forEach(function (b) {
            var a = Number(b.dataset.priceMin) || cfg.min,
                z = Number(b.dataset.priceMax) || cfg.max;
            var on =
                state.min === Math.max(cfg.min, a) &&
                state.max === Math.min(cfg.max, z);
            b.classList.toggle("is-on", on);
            b.setAttribute("aria-pressed", on ? "true" : "false");
        });

        if (sortSel) sortSel.value = state.sort;
        // thanh sắp xếp nhanh + dòng ghi chú "đang sắp xếp theo ..." cạnh số kết quả
        $$("[data-sort]").forEach(function (b) {
            var on = b.dataset.sort === state.sort;
            b.classList.toggle("is-on", on);
            b.setAttribute("aria-checked", on ? "true" : "false");
            b.tabIndex = on ? 0 : -1; // roving tabindex: Tab vào nhóm một lần, mũi tên để chọn
        });
        var sortNote = $("#tvSortNote");
        if (sortNote)
            sortNote.textContent =
                state.sort && state.sort !== "new" && SORT_LABEL[state.sort]
                    ? "· " + SORT_LABEL[state.sort]
                    : "";
        if (domainSel) domainSel.value = state.domain;
        if (fromIn) fromIn.value = state.from;
        if (toIn) toIn.value = state.to;
        if (guestOut)
            guestOut.textContent = state.guests
                ? state.guests + " khách"
                : "Bất kỳ";

        minIn.value = state.min;
        maxIn.value = state.max;
        var span = cfg.max - cfg.min || 1;
        fill.style.left = ((state.min - cfg.min) / span) * 100 + "%";
        fill.style.right = 100 - ((state.max - cfg.min) / span) * 100 + "%";
        minLbl.textContent = money(state.min);
        maxLbl.textContent = money(state.max);

        kwInput.value = state.keyword;
        kwClear.hidden = !state.keyword;

        // thẻ bộ lọc đang áp dụng
        var tags = [];
        if (state.keyword) tags.push(["keyword", "Từ khóa: " + state.keyword]);
        if (state.cat) {
            var chip = $("[data-filter='cat'][data-value='" + state.cat + "']");
            tags.push(["cat", chip ? chip.textContent.trim() : state.cat]);
        }
        if (state.dest) tags.push(["dest", state.dest]);
        if (state.from || state.to)
            tags.push([
                "dates",
                "Ngày: " +
                    (state.from ? fmtDate(state.from) : "…") +
                    " → " +
                    (state.to ? fmtDate(state.to) : "…"),
            ]);
        if (state.guests) tags.push(["guests", state.guests + " khách"]);
        if (state.domain) tags.push(["domain", LABELS.domain[state.domain]]);
        if (state.days) tags.push(["days", LABELS.days[state.days]]);
        if (state.rating) tags.push(["rating", "Từ " + state.rating + " sao"]);
        if (state.min !== cfg.min || state.max !== cfg.max)
            tags.push(["price", money(state.min) + " – " + money(state.max)]);

        activeEl.innerHTML = tags.length
            ? tags
                  .map(function (t) {
                      return (
                          '<span class="tl-tag">' +
                          esc(t[1]) +
                          '<button type="button" data-remove="' +
                          t[0] +
                          '" aria-label="Bỏ lọc ' +
                          esc(t[1]) +
                          '"><i class="fal fa-times" aria-hidden="true"></i></button></span>'
                      );
                  })
                  .join("") +
              '<button type="button" class="tl-link" data-action="reset">Xóa tất cả</button>'
            : "";
    }

    /* ---------- tải dữ liệu ---------- */
    var ctrl = null;
    function load(opts) {
        opts = opts || {};
        if (ctrl) ctrl.abort();
        ctrl = window.AbortController ? new AbortController() : null;

        paint();
        var qs = toQuery(true);
        history.replaceState(
            null,
            "",
            location.pathname + (qs ? "?" + qs : ""),
        );

        results.classList.add("is-loading");
        results.setAttribute("aria-busy", "true");

        fetch(cfg.url + "?" + qs, {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            signal: ctrl ? ctrl.signal : undefined,
            credentials: "same-origin",
        })
            .then(function (r) {
                if (!r.ok) throw new Error("HTTP " + r.status);
                return r.json();
            })
            .then(function (d) {
                results.innerHTML = d.html;
                countEl.innerHTML = "<b>" + d.total + "</b> tour phù hợp";
                state.page = d.page;
                document.dispatchEvent(new Event("wt:cards")); // vẽ lại trạng thái tim yêu thích
                if (opts.scroll) scrollToTop();
            })
            .catch(function (err) {
                if (err && err.name === "AbortError") return;
                if (window.console)
                    console.error("[tours] lỗi tải danh sách:", err);
                results.innerHTML =
                    '<div class="tv-empty"><div class="tv-empty__icon"><i class="fal fa-exclamation-triangle"></i></div>' +
                    "<h3>Không tải được danh sách tour</h3><p>Vui lòng kiểm tra kết nối rồi thử lại.</p>" +
                    '<button type="button" class="tv-btn-solid" data-action="retry">Thử lại</button></div>';
            })
            .then(function () {
                results.classList.remove("is-loading");
                results.removeAttribute("aria-busy");
            });
    }
    function scrollToTop() {
        var t = $(".tl-toolbar");
        if (t)
            window.scrollTo({
                top: t.getBoundingClientRect().top + window.pageYOffset - 100,
                behavior: "smooth",
            });
    }
    function apply(opts) {
        state.page = 1;
        load(opts);
    }

    /* ---------- sự kiện click (uỷ quyền) ---------- */
    document.addEventListener("click", function (e) {
        var t = e.target;

        var so = t.closest("[data-sort]");
        if (so) {
            if (state.sort !== so.dataset.sort) {
                state.sort = so.dataset.sort;
                apply();
            }
            return;
        }

        var f = t.closest("[data-filter]");
        if (f) {
            var key = f.dataset.filter,
                val = f.dataset.value;
            var same = String(state[key]) === val;
            state[key] = same
                ? key === "rating"
                    ? 0
                    : ""
                : key === "rating"
                  ? Number(val)
                  : val;
            apply();
            return;
        }

        var pr = t.closest("[data-price-min]");
        if (pr) {
            var a = Math.max(cfg.min, Number(pr.dataset.priceMin) || cfg.min),
                z = Math.min(cfg.max, Number(pr.dataset.priceMax) || cfg.max);
            var isOn = state.min === a && state.max === z;
            state.min = isOn ? cfg.min : a;
            state.max = isOn ? cfg.max : z;
            apply();
            return;
        }

        var g = t.closest("[data-guest]");
        if (g) {
            state.guests = Math.max(
                0,
                Math.min(30, state.guests + Number(g.dataset.guest)),
            );
            paint();
            return;
        }

        var rm = t.closest("[data-remove]");
        if (rm) {
            var k = rm.dataset.remove;
            if (k === "price") {
                state.min = cfg.min;
                state.max = cfg.max;
            } else if (k === "dates") {
                state.from = "";
                state.to = "";
            } else if (k === "rating" || k === "guests") state[k] = 0;
            else state[k] = "";
            apply();
            return;
        }

        var a2 = t.closest("[data-action]");
        if (a2) {
            var act = a2.dataset.action;
            if (act === "reset") {
                state = Object.assign({}, DEFAULT, { sort: state.sort });
                apply();
                closeFilter();
            } else if (act === "retry") load();
            else if (act === "clear-cat") {
                state.cat = "";
                apply();
            } else if (act === "open-filter") openFilter();
            else if (act === "close-filter") closeFilter();
            return;
        }

        var pg = t.closest(".tv-pager a[data-page]");
        if (pg) {
            e.preventDefault();
            state.page = Number(pg.dataset.page);
            load({ scroll: true });
            return;
        }

        if (t.closest("#tvKeywordClear")) {
            state.keyword = "";
            apply();
            kwInput.focus();
        }
    });

    /* ---------- ô tìm kiếm: gõ là lọc (debounce) + Enter ---------- */
    var timer;
    kwInput.addEventListener("input", function () {
        kwClear.hidden = !kwInput.value;
        clearTimeout(timer);
        timer = setTimeout(function () {
            var v = kwInput.value.trim();
            if (v !== state.keyword) {
                state.keyword = v;
                apply();
            }
        }, 450);
    });
    form.addEventListener("submit", function (e) {
        e.preventDefault();
        clearTimeout(timer);
        state.keyword = kwInput.value.trim();
        apply({ scroll: true });
    });

    /* ---------- ngày, miền, sắp xếp ---------- */
    function onDates() {
        var f = fromIn.value,
            t = toIn.value;
        if (f && t && t < f) {
            // người dùng chọn ngày về sớm hơn ngày đi: tự sửa để không ra 0 kết quả khó hiểu
            if (this === fromIn) t = f;
            else f = t;
        }
        state.from = f;
        state.to = t;
        apply();
    }
    if (fromIn) fromIn.addEventListener("change", onDates);
    if (toIn) toIn.addEventListener("change", onDates);
    if (domainSel)
        domainSel.addEventListener("change", function () {
            state.domain = domainSel.value;
            apply();
        });
    if (sortSel)
        sortSel.addEventListener("change", function () {
            state.sort = sortSel.value;
            apply();
        });

    /* ---------- sắp xếp nhanh: dùng được bằng bàn phím (mũi tên trái/phải) ---------- */
    var sortBar = $("#tvSortBar");
    if (sortBar)
        sortBar.addEventListener("keydown", function (e) {
            var chips = $$("[data-sort]", sortBar),
                i = chips.indexOf(document.activeElement);
            if (i < 0) return;
            var to = null;
            if (e.key === "ArrowRight" || e.key === "ArrowDown")
                to = (i + 1) % chips.length;
            else if (e.key === "ArrowLeft" || e.key === "ArrowUp")
                to = (i - 1 + chips.length) % chips.length;
            if (to === null) return;
            e.preventDefault();
            chips[to].focus();
            chips[to].click();
        });

    /* ---------- lọc nhanh danh sách điểm đến (chỉ trên trình duyệt) ---------- */
    var ds = $("#tvDestSearch");
    if (ds)
        ds.addEventListener("input", function () {
            var q = ds.value.trim().toLowerCase();
            $$("#tvDestList .tl-opt").forEach(function (b) {
                b.hidden = q && b.dataset.name.indexOf(q) === -1;
            });
        });

    /* ---------- thanh trượt giá: cập nhật nhãn khi kéo, tải khi thả ---------- */
    function syncRange(changed) {
        var lo = Number(minIn.value),
            hi = Number(maxIn.value);
        if (lo > hi) {
            if (changed === minIn) lo = hi;
            else hi = lo;
        }
        state.min = lo;
        state.max = hi;
        paint();
    }
    minIn.addEventListener("input", function () {
        syncRange(minIn);
    });
    maxIn.addEventListener("input", function () {
        syncRange(maxIn);
    });
    minIn.addEventListener("change", function () {
        syncRange(minIn);
        apply();
    });
    maxIn.addEventListener("change", function () {
        syncRange(maxIn);
        apply();
    });

    /* ---------- ngăn kéo bộ lọc (tablet/điện thoại) ---------- */
    var lastFocus = null;
    function openFilter() {
        lastFocus = document.activeElement;
        filterBox.classList.add("is-open");
        overlay.classList.add("is-open");
        document.body.style.overflow = "hidden";
        var first = filterBox.querySelector("button, input");
        if (first) first.focus();
    }
    function closeFilter() {
        if (!filterBox.classList.contains("is-open")) return;
        filterBox.classList.remove("is-open");
        overlay.classList.remove("is-open");
        document.body.style.overflow = "";
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") closeFilter();
    });

    paint();
})();
