/* Hero "Dòng bưu ảnh": CSS 3D + JS thuần. Đặc tả: tài liệu mục 4.1.1. */
(function () {
    "use strict";
    var root = document.getElementById("hero");
    if (!root || !root.classList.contains("hp")) return;

    var q = function (s) {
        return root.querySelector(s);
    };
    var world = q(".hp-world"),
        track = q(".hp-track"),
        bgs = root.querySelectorAll(".hp-bg");
    var mqMobile = matchMedia("(max-width:599px)"),
        mqReduce = matchMedia("(prefers-reduced-motion: reduce)");
    var canHover = matchMedia("(hover:hover) and (pointer:fine)").matches;

    /* Thông số chuyển động (bảng trong tài liệu). Chỉnh ở đây. */
    var K = { gap: 620, back: 0.45, R: 0.26, Ry: 0.13, lag: 0.09, rowH: 0.7 };

    var month = +root.dataset.month,
        planner = root.dataset.planner,
        api = root.dataset.api;
    var cards = [],
        items = [],
        cur = 0,
        tgt = 0,
        idx = -1,
        m3 = false,
        vis = true,
        front = 0;
    var mx = 0,
        my = 0,
        hx = 0,
        hy = 0,
        W = innerWidth,
        H = innerHeight,
        top0 = 0,
        added = {},
        loading = false;

    function clamp(v, a, b) {
        return Math.max(a, Math.min(b, v));
    }
    function measure() {
        W = innerWidth;
        H = innerHeight;
        top0 = track.getBoundingClientRect().top + (window.pageYOffset || 0);
    }

    /* ---- đọc DOM do Blade render ---- */
    function read() {
        cards = [].slice.call(world.querySelectorAll(".hp-card"));
        items = cards
            .filter(function (c) {
                return !c.dataset.blank;
            })
            .map(function (c) {
                return {
                    slug: c.dataset.slug,
                    name: c.dataset.name,
                    why: c.dataset.why,
                    url: c.getAttribute("href"),
                    credit: c.dataset.credit,
                    creditUrl: c.dataset.creditUrl,
                    img:
                        c.querySelector("img").currentSrc ||
                        c.querySelector("img").src,
                };
            });
        cards.forEach(function (c, i) {
            c.dataset.rz =
                (((i * 7) % 5) - 2) * 1.25; /* nghiêng Z cố định ±2,5° */
            c.onclick = function (e) {
                if (m3 && i !== idx) {
                    e.preventDefault();
                    goTo(i);
                } /* tấm khác: cuộn tới; tấm hiện tại: đi theo liên kết */
            };
        });
        root.style.setProperty("--n", cards.length);
    }

    /* ---- bảng thông tin + nền mờ + bộ đếm ---- */
    function update(i) {
        if (i === idx) return;
        idx = i;
        var last = i >= items.length,
            it = items[Math.min(i, items.length - 1)];
        q(".hp-p-name").textContent = last ? "Bưu ảnh trắng" : it.name;
        q(".hp-p-why").textContent = last
            ? "Viết chuyến đi của riêng bạn cho tháng " + month + "."
            : it.why;
        var go = q(".hp-p-go"),
            add = q(".hp-p-add"),
            cr = q(".hp-p-credit");
        go.textContent = last ? "Lên kế hoạch" : "Xem điểm đến";
        go.href = last ? planner + month : it.url;
        add.hidden = last;
        add.textContent =
            !last && added[it.slug] ? "✓ Đã thêm" : "+ Thêm vào chuyến đi";
        cr.textContent = "";
        if (!last && it.credit) {
            cr.appendChild(document.createTextNode("Ảnh: "));
            if (it.creditUrl && /^https?:\/\//.test(it.creditUrl)) {
                var a = document.createElement("a");
                a.href = it.creditUrl;
                a.target = "_blank";
                a.rel = "noopener";
                a.textContent = it.credit;
                cr.appendChild(a);
            } else cr.appendChild(document.createTextNode(it.credit));
        }
        q(".hp-count").textContent =
            ("0" + (i + 1)).slice(-2) + " / " + ("0" + cards.length).slice(-2);
        var on = bgs[front ^ 1],
            off = bgs[front];
        front ^= 1;
        on.style.backgroundImage = "url('" + it.img + "')";
        on.classList.add("on");
        off.classList.remove("on");
    }

    /* ---- vẽ 3D ---- */
    function render(p) {
        if (!m3) return;
        var R = W * K.R * (W < 1024 ? 0.7 : 1),
            Ry = H * K.Ry;
        var sx = R * Math.sin(1.05 * p),
            sy = Ry * Math.sin(0.7 * p + 1);
        for (var i = 0; i < cards.length; i++) {
            var c = cards[i],
                d = i - p;
            var o = d < 0 ? 1 + d / 0.8 : d > 4 ? 1 - (d - 4) / 2.5 : 1;
            o = clamp(o, 0, 1);
            if (o < 0.02) {
                c.style.visibility = "hidden";
                continue;
            }
            var x = R * Math.sin(1.05 * i) - sx,
                y = Ry * Math.sin(0.7 * i + 1) - sy,
                z = -(d + K.back) * K.gap;
            c.style.visibility = "visible";
            c.style.opacity = o.toFixed(3);
            c.style.transform =
                "translate(-50%,-50%) translate3d(" +
                x.toFixed(1) +
                "px," +
                y.toFixed(1) +
                "px," +
                z.toFixed(1) +
                "px) rotateY(" +
                ((-x / R) * 22).toFixed(2) +
                "deg) rotateZ(" +
                c.dataset.rz +
                "deg)";
        }
        hx += (mx - hx) * 0.08;
        hy += (my - hy) * 0.08;
        world.style.transform =
            "rotateX(" +
            (-hy * 4).toFixed(2) +
            "deg) rotateY(" +
            (hx * 6).toFixed(2) +
            "deg)";
        q(".hp-intro").style.opacity = clamp(1 - p * 2, 0, 1);
    }
    function tick() {
        if (!vis || !m3) return;
        cur += (tgt - cur) * K.lag;
        if (Math.abs(tgt - cur) < 0.0005) cur = tgt;
        render(cur);
        update(Math.round(clamp(cur, 0, cards.length - 1)));
        requestAnimationFrame(tick);
    }
    function onScroll() {
        if (m3)
            tgt = clamp(
                ((window.pageYOffset || 0) - top0) / (H * K.rowH),
                0,
                cards.length - 1,
            );
    }
    function goTo(i) {
        i = clamp(i, 0, cards.length - 1);
        if (m3) scrollTo({ top: top0 + i * H * K.rowH, behavior: "smooth" });
        else
            cards[i].scrollIntoView({
                behavior: "smooth",
                inline: "center",
                block: "nearest",
            });
    }

    /* ---- chế độ: 3D / băng vuốt (mobile) / lưới tĩnh (reduced-motion) ---- */
    function setMode() {
        m3 = !mqMobile.matches && !mqReduce.matches;
        root.classList.toggle("hp--3d", m3);
        if (!m3) {
            cards.forEach(function (c) {
                c.style.cssText = "";
            });
            world.style.transform = "";
            q(".hp-intro").style.opacity = "";
        }
        measure();
        onScroll();
        if (m3) {
            cur = tgt;
            render(cur);
            requestAnimationFrame(tick);
        }
        idx = -1;
        update(Math.round(clamp(cur, 0, cards.length - 1)));
    }

    /* ---- đổi tháng: GET /api/v1/seasons?month= ---- */
    function make(it, i, m) {
        var a = document.createElement("a");
        a.className = "hp-card";
        a.href = it.url;
        a.dataset.slug = it.slug;
        a.dataset.name = it.name;
        a.dataset.why = it.why;
        a.dataset.credit = it.credit || "";
        a.dataset.creditUrl = it.creditUrl || "";
        a.setAttribute(
            "aria-label",
            it.name + ", " + it.province + ". " + it.why,
        );
        var f = document.createElement("span");
        f.className = "hp-img";
        var im = document.createElement("img");
        im.src = it.image;
        im.alt = it.name + ", " + it.province;
        im.decoding = "async";
        if (i >= 3) im.loading = "lazy";
        f.appendChild(im);
        var cap = document.createElement("span");
        cap.className = "hp-cap";
        var b = document.createElement("b");
        b.textContent = it.name;
        var p = document.createElement("i");
        p.textContent = it.province;
        cap.appendChild(b);
        cap.appendChild(p);
        var st = document.createElement("span");
        st.className = "hp-stamp";
        st.setAttribute("aria-hidden", "true");
        st.textContent = "T" + m;
        a.appendChild(f);
        a.appendChild(cap);
        a.appendChild(st);
        return a;
    }
    function season(m) {
        return m >= 2 && m <= 4
            ? "spring"
            : m <= 7 && m >= 5
              ? "summer"
              : m >= 8 && m <= 10
                ? "autumn"
                : "winter";
    }
    function setMonth(m) {
        if (loading || m === month) return;
        loading = true;
        root.classList.add("hp--wait");
        fetch(api + "?month=" + m, { headers: { Accept: "application/json" } })
            .then(function (r) {
                if (!r.ok) throw 0;
                return r.json();
            })
            .then(function (d) {
                if (!d.items || d.items.length < 3) throw 0;
                setTimeout(function () {
                    var blank = world.querySelector(".hp-blank");
                    world
                        .querySelectorAll(".hp-card:not(.hp-blank)")
                        .forEach(function (n) {
                            n.remove();
                        });
                    d.items.forEach(function (it, i) {
                        world.insertBefore(make(it, i, m), blank);
                    });
                    month = m;
                    root.dataset.month = m;
                    root.dataset.season = season(m);
                    blank.href = planner + m;
                    blank.setAttribute(
                        "aria-label",
                        "Bưu ảnh trắng: lên kế hoạch cho tháng " + m,
                    );
                    root.querySelectorAll(".hp-month").forEach(function (n) {
                        n.textContent = m;
                    });
                    root.querySelectorAll(".hp-chip").forEach(function (c) {
                        c.setAttribute("aria-pressed", +c.dataset.month === m);
                    });
                    read();
                    idx = -1;
                    cur = tgt = 0;
                    if (m3) scrollTo({ top: top0, behavior: "auto" });
                    measure();
                    render(0);
                    update(0);
                    root.classList.remove("hp--wait");
                    loading = false;
                }, 300);
            })
            .catch(function () {
                root.classList.remove("hp--wait");
                loading = false;
            });
    }

    /* ---- sự kiện ---- */
    root.querySelectorAll(".hp-chip").forEach(function (c) {
        c.addEventListener("click", function () {
            setMonth(+c.dataset.month);
        });
    });
    q(".hp-p-add").addEventListener("click", function () {
        var it = items[idx];
        if (!it) return;
        added[it.slug] = true;
        this.textContent = "✓ Đã thêm";
        /* Khi có khay chuyến đi (tbl_trip_items), lắng nghe sự kiện này để lưu */
        document.dispatchEvent(
            new CustomEvent("travela:trip-add", {
                detail: { type: "destination", slug: it.slug, name: it.name },
            }),
        );
    });
    addEventListener("scroll", onScroll, { passive: true });
    addEventListener("resize", function () {
        measure();
        onScroll();
        render(cur);
    });
    addEventListener("load", function () {
        measure();
        onScroll();
    });
    q(".hp-stage").addEventListener("pointermove", function (e) {
        if (!canHover || !m3 || W < 1024) return;
        mx = e.clientX / W - 0.5;
        my = e.clientY / H - 0.5;
    });
    addEventListener("keydown", function (e) {
        if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") return;
        var t = e.target && e.target.tagName;
        if (t === "INPUT" || t === "SELECT" || t === "TEXTAREA") return;
        var r = root.getBoundingClientRect();
        if (r.bottom < 0 || r.top > H) return;
        e.preventDefault();
        goTo(idx + (e.key === "ArrowRight" ? 1 : -1));
    });
    world.addEventListener(
        "scroll",
        function () {
            /* băng vuốt ngang trên mobile */
            if (m3 || !cards.length) return;
            update(
                clamp(
                    Math.round(world.scrollLeft / (cards[0].offsetWidth + 16)),
                    0,
                    cards.length - 1,
                ),
            );
        },
        { passive: true },
    );
    new IntersectionObserver(function (en) {
        vis = en[0].isIntersecting;
        if (vis && m3) requestAnimationFrame(tick);
    }).observe(root);
    mqMobile.addEventListener("change", setMode);
    mqReduce.addEventListener("change", setMode);

    read();
    setMode();
    root.dataset.ready = "1";
})();
