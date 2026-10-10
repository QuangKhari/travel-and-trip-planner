/* Trang /tours — lọc, sắp xếp, tìm kiếm bằng AJAX (JS thuần, không cần jQuery).
   Máy chủ: GET /filter-tours  →  JSON { html, total, page, lastPage }.
   Trạng thái được đồng bộ lên URL (?keyword=&domain=&days=&rating=&min=&max=&sort=&page=) nên chia sẻ link được. */
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
    var minIn = $("#tvMin"),
        maxIn = $("#tvMax"),
        fill = $("#tvRangeFill");
    var minLbl = $("#tvMinLabel"),
        maxLbl = $("#tvMaxLabel");
    var filterBox = $("#tvFilter"),
        overlay = $("#tvOverlay");

    var DEFAULT = {
        keyword: "",
        domain: "",
        days: "",
        rating: 0,
        min: cfg.min,
        max: cfg.max,
        sort: "new",
        page: 1,
    };
    var state = Object.assign({}, DEFAULT, cfg.state || {});
    state.rating = Number(state.rating) || 0;
    state.min = Number(state.min);
    state.max = Number(state.max);
    state.page = Number(state.page) || 1;

    var LABELS = {
        domain: { b: "Miền Bắc", t: "Miền Trung", n: "Miền Nam" },
        days: { short: "1–3 ngày", mid: "4–5 ngày", long: "6 ngày trở lên" },
    };
    var money = function (n) {
        return Number(n).toLocaleString("vi-VN") + "đ";
    };

    /* ---------- query string ---------- */
    function toQuery(withPage) {
        var p = new URLSearchParams();
        if (state.keyword) p.set("keyword", state.keyword);
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
        $$("[data-sort]").forEach(function (b) {
            b.classList.toggle("is-on", b.dataset.sort === state.sort);
        });

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
        if (state.domain) tags.push(["domain", LABELS.domain[state.domain]]);
        if (state.days) tags.push(["days", LABELS.days[state.days]]);
        if (state.rating) tags.push(["rating", "Từ " + state.rating + " sao"]);
        if (state.min !== cfg.min || state.max !== cfg.max)
            tags.push(["price", money(state.min) + " – " + money(state.max)]);
        activeEl.innerHTML = tags.length
            ? tags
                  .map(function (t) {
                      return (
                          '<span class="tv-tagx">' +
                          escapeHtml(t[1]) +
                          '<button type="button" data-remove="' +
                          t[0] +
                          '" aria-label="Bỏ lọc"><i class="fal fa-times"></i></button></span>'
                      );
                  })
                  .join("") +
              '<button type="button" class="tv-clearall" data-action="reset">Xóa tất cả</button>'
            : "";
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            }[c];
        });
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
                countEl.innerHTML = "Tìm thấy <b>" + d.total + "</b> tour";
                state.page = d.page;
                if (opts.scroll) scrollToTop();
            })
            .catch(function (err) {
                if (err && err.name === "AbortError") return;
                if (window.console)
                    console.error("[tours] lỗi tải danh sách:", err);
                results.innerHTML =
                    '<div class="tv-empty"><div class="tv-empty__icon"><i class="fal fa-exclamation-triangle"></i></div>' +
                    "<h3>Không tải được danh sách tour</h3><p>Vui lòng kiểm tra kết nối hoặc thử lại.</p>" +
                    '<button type="button" class="tv-btn-solid" data-action="retry">Thử lại</button></div>';
            })
            .then(function () {
                results.classList.remove("is-loading");
                results.removeAttribute("aria-busy");
            });
    }
    function scrollToTop() {
        var t = $(".tv-toolbar");
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

    /* ---------- sự kiện ---------- */
    document.addEventListener("click", function (e) {
        var t = e.target;

        var f = t.closest("[data-filter]");
        if (f) {
            var key = f.dataset.filter,
                val = f.dataset.value;
            var cur = String(state[key]);
            state[key] =
                cur === val
                    ? key === "rating"
                        ? 0
                        : ""
                    : key === "rating"
                      ? Number(val)
                      : val;
            apply();
            return;
        }
        var s = t.closest("[data-sort]");
        if (s) {
            state.sort = s.dataset.sort;
            apply();
            return;
        }

        var rm = t.closest("[data-remove]");
        if (rm) {
            var k = rm.dataset.remove;
            if (k === "price") {
                state.min = cfg.min;
                state.max = cfg.max;
            } else if (k === "rating") state.rating = 0;
            else state[k] = "";
            apply();
            return;
        }

        var a = t.closest("[data-action]");
        if (a) {
            var act = a.dataset.action;
            if (act === "reset") {
                state = Object.assign({}, DEFAULT, { sort: state.sort });
                apply();
                closeFilter();
            } else if (act === "retry") load();
            else if (act === "open-filter") openFilter();
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

        var q = t.closest(".tv-qchip");
        if (q) {
            state.keyword = q.dataset.kw;
            apply({ scroll: true });
            return;
        }

        if (t.closest("#tvKeywordClear")) {
            state.keyword = "";
            apply();
            kwInput.focus();
        }
    });

    // ô tìm kiếm: gõ là lọc (debounce) + Enter
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

    // thanh trượt giá: cập nhật nhãn khi kéo, tải khi thả
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

    // ngăn kéo bộ lọc (điện thoại)
    function openFilter() {
        filterBox.classList.add("is-open");
        overlay.classList.add("is-open");
        document.body.style.overflow = "hidden";
    }
    function closeFilter() {
        filterBox.classList.remove("is-open");
        overlay.classList.remove("is-open");
        document.body.style.overflow = "";
    }
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") closeFilter();
    });

    paint();
})();
