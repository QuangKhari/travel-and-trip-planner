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
    var K = { gap: 620, back: 0.85, R: 0.26, Ry: 0.13, lag: 0.09, rowH: 0.7 };

    var month = +root.dataset.month,
        planner = root.dataset.planner || "",
        api = root.dataset.api;
    var cards = [],
        items = [],
        cur = 0,
        tgt = 0,
        idx = -1,
        m3 = false,
        vis = true,
        front = 0;
    var running = false /* chỉ một vòng vẽ được chạy */,
        pointerActive = false;
    var mx = 0,
        my = 0,
        hx = 0,
        hy = 0,
        W = innerWidth,
        H = innerHeight,
        top0 = 0,
        loading = false;
    /* Khay chuyến đi dùng chung (travela-trip.js). Nếu chưa nạp thì nút ẩn đi, không hứa mà không lưu. */
    var trip = window.TravelaTrip || null;

    function clamp(v, a, b) {
        return Math.max(a, Math.min(b, v));
    }
    function inTrip(it) {
        return !!(trip && it && trip.has(it.type || "destination", it.slug));
    }
    function addLabel(it) {
        return inTrip(it)
            ? "✓ Đã thêm (" + trip.count() + ")"
            : "+ Thêm vào chuyến đi";
    }
    /* Bưu ảnh trắng: có Planner thì dẫn vào /len-ke-hoach?thang=N, chưa có thì xuống khối lên kế hoạch ngay dưới hero */
    function plannerHref(m) {
        return planner
            ? planner + (planner.indexOf("?") < 0 ? "?" : "&") + "thang=" + m
            : "#hpl";
    }
    function plannerLabel() {
        return planner
            ? "Lên kế hoạch cho tháng " + month + " →"
            : "Lên kế hoạch ngay bên dưới →";
    }
    function measure() {
        W = innerWidth;
        H = innerHeight;
        top0 = track.getBoundingClientRect().top + (window.pageYOffset || 0);
    }

    function centerActiveMonth() {
        var chips = q(".hp-chips");
        var on = root.querySelector('.hp-chip[aria-pressed="true"]');

        if (!chips || !on) return;

        chips.scrollLeft =
            on.offsetLeft - chips.clientWidth / 2 + on.offsetWidth / 2;
    }

    function showNotice(message) {
        var notice = q(".hp-notice");
        if (!notice) return;

        notice.textContent = message;
        notice.hidden = false;

        clearTimeout(showNotice.timer);
        showNotice.timer = setTimeout(function () {
            notice.hidden = true;
        }, 3000);
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
                    type: c.dataset.type || "destination",
                    slug: c.dataset.slug,
                    name: c.dataset.name,
                    why: c.dataset.why,
                    url: c.getAttribute("href"),
                    credit: c.dataset.credit,
                    creditUrl: c.dataset.creditUrl,
                    img:
                        c.querySelector("img").currentSrc ||
                        c.querySelector("img").src,
                    thumb: c.dataset.thumb || "",
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
            ? planner
                ? "Viết chuyến đi của riêng bạn cho tháng " + month + "."
                : "Chọn một cảm hứng để bắt đầu chuyến đi tháng " + month + "."
            : it.why;
        var go = q(".hp-p-go"),
            add = q(".hp-p-add"),
            cr = q(".hp-p-credit");
        go.textContent = last
            ? planner
                ? "Lên kế hoạch"
                : "Khám phá theo cảm hứng"
            : it.type === "event"
              ? "Xem lễ hội"
              : "Xem điểm đến";
        go.href = last ? plannerHref(month) : it.url;
        add.hidden = last || !trip;
        if (!last) add.textContent = addLabel(it);
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
        /* nền mờ: ưu tiên bản thu nhỏ (khoảng 48 px) phóng to; chưa có thì dùng ảnh đầy đủ */
        on.style.backgroundImage = "url('" + (it.thumb || it.img) + "')";
        on.classList.toggle("hp-bg--thumb", !!it.thumb);
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
    /* Vùng đọc riêng cho trình đọc màn hình: chỉ ghi chữ khi đã dừng ở một tấm,
       nên lướt qua nhiều tấm không bị đọc dồn. Dùng được cả 3D, băng vuốt và lưới tĩnh. */
    var announcedIdx = -1;
    function announce() {
        if (idx < 0 || idx === announcedIdx) return;
        announcedIdx = idx;
        var sr = q(".hp-sr");
        if (!sr) return;
        var last = idx >= items.length;
        var it = items[Math.min(idx, items.length - 1)];
        sr.textContent = last
            ? "Bưu ảnh trắng. " + q(".hp-p-why").textContent
            : it.name + ". " + it.why;
    }
    function start() {
        /* gọi bao nhiêu lần cũng chỉ có một vòng rAF */
        if (running || !m3 || !vis) return;
        running = true;
        requestAnimationFrame(tick);
    }
    function tick() {
        if (!vis || !m3) {
            running = false;
            return;
        }

        cur += (tgt - cur) * K.lag;

        var settled = Math.abs(tgt - cur) < 0.0005;

        if (settled) cur = tgt;

        render(cur);
        update(Math.round(clamp(cur, 0, cards.length - 1)));
        if (settled) announce();

        var pointerMoving =
            Math.abs(mx - hx) > 0.001 || Math.abs(my - hy) > 0.001;

        if (settled && !pointerMoving) {
            running = false;
            return;
        }

        requestAnimationFrame(tick);
    }
    function onScroll() {
        if (!m3) return;
        tgt = clamp(
            ((window.pageYOffset || 0) - top0) / (H * K.rowH),
            0,
            cards.length - 1,
        );
        start(); /* vòng vẽ tự dừng khi đã yên: cuộn là chạy lại */
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
            start();
        }
        idx = -1;
        update(Math.round(clamp(cur, 0, cards.length - 1)));
    }

    /* ---- đổi tháng: GET /api/v1/seasons?month= ---- */
    function make(it, i, m) {
        var a = document.createElement("a");
        a.className = "hp-card";
        a.href = it.url;
        a.dataset.type = it.type || "destination";
        a.dataset.slug = it.slug;
        a.dataset.name = it.name;
        a.dataset.why = it.why;
        a.dataset.thumb = it.thumb || "";
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
        var told = false;
        root.classList.add("hp--wait");
        fetch(api + "?month=" + m, { headers: { Accept: "application/json" } })
            .then(function (r) {
                if (!r.ok) throw 0;
                return r.json();
            })
            .then(function (d) {
                if (!d.items || d.items.length < 3) {
                    told = true;
                    showNotice(
                        "Tháng " + m + " hiện chưa có đủ điểm đến nổi bật.",
                    );
                    throw 0;
                }
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
                    blank.href = plannerHref(m);
                    blank.setAttribute(
                        "aria-label",
                        "Bưu ảnh trắng: " + plannerLabel().replace(" →", ""),
                    );
                    var go = blank.querySelector(".hp-go");
                    if (go) go.textContent = plannerLabel();
                    root.querySelectorAll(".hp-month").forEach(function (n) {
                        n.textContent = m;
                    });
                    root.querySelectorAll(".hp-chip").forEach(function (c) {
                        c.setAttribute("aria-pressed", +c.dataset.month === m);
                    });
                    centerActiveMonth();
                    read();
                    idx = -1;
                    announcedIdx = -1;
                    cur = tgt = 0;
                    if (m3) scrollTo({ top: top0, behavior: "auto" });
                    measure();
                    render(0);
                    update(0);
                    announce();
                    root.classList.remove("hp--wait");
                    loading = false;
                }, 300);
            })
            .catch(function () {
                if (!told)
                    showNotice(
                        "Không tải được dữ liệu tháng " +
                            m +
                            ", bạn thử lại sau nhé.",
                    );
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
        if (!it || !trip) return;
        /* bấm lần nữa thì bỏ khỏi khay (nút bật/tắt) */
        if (trip.has(it.type, it.slug)) trip.remove(it.type, it.slug);
        else
            trip.add({
                type: it.type,
                slug: it.slug,
                name: it.name,
                url: it.url,
            });
        this.textContent = addLabel(it);
    });
    document.addEventListener("travela:trip-change", function () {
        var it = items[idx];
        if (it && idx < items.length) q(".hp-p-add").textContent = addLabel(it);
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

        pointerActive = true;
        mx = e.clientX / W - 0.5;
        my = e.clientY / H - 0.5;

        start();
    });

    q(".hp-stage").addEventListener("pointerleave", function () {
        if (!canHover || !m3 || W < 1024) return;

        pointerActive = false;
        mx = 0;
        my = 0;

        start();
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
            clearTimeout(announce.t);
            announce.t = setTimeout(announce, 350); /* đọc sau khi vuốt dừng */
        },
        { passive: true },
    );
    new IntersectionObserver(function (en) {
        vis = en[0].isIntersecting;
        start();
    }).observe(root);
    mqMobile.addEventListener("change", setMode);
    mqReduce.addEventListener("change", setMode);

    read();
    setMode();
    centerActiveMonth();
    addEventListener("load", centerActiveMonth);
    announcedIdx = idx;
    root.dataset.ready = "1";
})();
