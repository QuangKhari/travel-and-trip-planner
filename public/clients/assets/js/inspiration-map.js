/* Bản đồ cảm xúc — "Khám phá theo cảm hứng" (Home).
   Đọc JSON trong #tvxMapData (do InspirationService::mapData() tạo), vẽ pin lên #tvxPins,
   đổi cảm hứng thì pin sáng/to theo điểm (score), bấm pin để xem thẻ điểm đến. Không phụ thuộc thư viện. */
(function () {
    "use strict";

    var root = document.getElementById("tvxMap");
    var dataEl = document.getElementById("tvxMapData");
    if (!root || !dataEl) return;

    var data;
    try {
        data = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }

    var pinsEl = document.getElementById("tvxPins");
    var popEl = document.getElementById("tvxPop");
    var handEl = document.getElementById("tvxHand");
    var topEl = document.getElementById("tvxTop");
    var ctaEl = document.getElementById("tvxCta");
    var ctaTextEl = document.getElementById("tvxCtaText");
    var litEl = document.getElementById("tvxLit");
    var tabs = Array.prototype.slice.call(root.querySelectorAll(".tvx-tab"));
    var board = root.querySelector(".tvx-map__board");

    var MIN_SCORE = 1; // có trong bảng điểm là "sáng"
    var LABELS = 4; // số nhãn tên hiển thị thường trực
    var reduced =
        window.matchMedia &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    var bySlug = {};
    data.inspirations.forEach(function (i) {
        bySlug[i.slug] = i;
    });
    var destBySlug = {};
    data.destinations.forEach(function (d) {
        destBySlug[d.slug] = d;
    });

    var active = root.getAttribute("data-active") || data.inspirations[0].slug;
    var openPin = null;
    var interacted = false;
    var timer = null;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }

    /* ---------- Vẽ pin một lần ---------- */
    var pins = {};
    data.destinations.forEach(function (d, idx) {
        var b = el("button", "tvx-pin");
        b.type = "button";
        b.style.left = d.x + "%";
        b.style.top = d.y + "%";
        b.style.setProperty("--i", idx);
        b.setAttribute("data-slug", d.slug);
        b.setAttribute("aria-label", d.name + ", " + d.province);
        b.appendChild(el("span", "tvx-pin__dot"));
        b.appendChild(el("span", "tvx-pin__label", d.name));
        b.addEventListener("click", function (e) {
            e.stopPropagation();
            stopAuto();
            togglePop(d.slug);
        });
        pinsEl.appendChild(b);
        pins[d.slug] = b;
    });

    /* ---------- Đổi cảm hứng ---------- */
    function ranked(slug) {
        return data.destinations
            .filter(function (d) {
                return (d.scores[slug] || 0) >= MIN_SCORE;
            })
            .sort(function (a, b) {
                return b.scores[slug] - a.scores[slug];
            });
    }

    function setActive(slug) {
        if (!bySlug[slug]) return;
        active = slug;
        root.setAttribute("data-active", slug);

        tabs.forEach(function (t) {
            var on = t.getAttribute("data-slug") === slug;
            t.setAttribute("aria-selected", on ? "true" : "false");
            t.classList.toggle("is-on", on);
        });

        var ins = bySlug[slug];
        var list = ranked(slug);

        // pin: sáng, kích thước theo điểm
        data.destinations.forEach(function (d) {
            var b = pins[d.slug];
            var s = d.scores[slug] || 0;
            var lit = s >= MIN_SCORE;
            b.classList.toggle("is-lit", lit);
            b.classList.remove("has-label");
            b.style.setProperty(
                "--k",
                lit
                    ? (0.55 + (Math.max(0, s - 40) / 60) * 0.95).toFixed(2)
                    : 0.4,
            );
            b.style.zIndex = lit ? String(10 + s) : "1";
            b.tabIndex = lit ? 0 : -1;
        });
        list.slice(0, LABELS).forEach(function (d) {
            pins[d.slug].classList.add("has-label");
        });
        requestAnimationFrame(avoidLabelCollisions);

        // khối chi tiết
        handEl.textContent = ins.headline;
        topEl.innerHTML = "";
        list.slice(0, 3).forEach(function (d, i) {
            if (i) topEl.appendChild(document.createTextNode(" · "));
            var a = el("button", "tvx-linkbtn", d.name);
            a.type = "button";
            a.addEventListener("click", function () {
                stopAuto();
                openPopFor(d.slug);
            });
            topEl.appendChild(a);
        });
        ctaEl.href =
            data.exploreUrl + "?inspiration=" + encodeURIComponent(slug);
        ctaTextEl.textContent = "Xem tất cả " + list.length + " điểm đến";
        litEl.textContent = list.length + " điểm sáng";

        // thẻ đang mở: cập nhật điểm theo cảm hứng mới (hoặc đóng nếu điểm không thuộc cảm hứng này)
        if (openPin) {
            if ((destBySlug[openPin].scores[slug] || 0) >= MIN_SCORE)
                renderPop(openPin);
            else closePop();
        }
    }

    /* Ẩn bớt nhãn nếu chồng lên nhau */
    function avoidLabelCollisions() {
        var shown = [];
        var labelled = pinsEl.querySelectorAll(".tvx-pin.has-label");
        Array.prototype.forEach.call(labelled, function (b) {
            var lbl = b.querySelector(".tvx-pin__label");
            var r = lbl.getBoundingClientRect();
            var hit = shown.some(function (o) {
                return !(
                    r.right < o.left - 4 ||
                    r.left > o.right + 4 ||
                    r.bottom < o.top - 2 ||
                    r.top > o.bottom + 2
                );
            });
            if (hit) b.classList.remove("has-label");
            else shown.push(r);
        });
    }

    /* ---------- Thẻ điểm đến ---------- */
    function togglePop(slug) {
        if (openPin === slug) closePop();
        else openPopFor(slug);
    }

    function openPopFor(slug) {
        var d = destBySlug[slug];
        if (!d) return;
        // pin chưa sáng ở cảm hứng này: chuyển sang cảm hứng hợp nhất của điểm đó
        if ((d.scores[active] || 0) < MIN_SCORE) {
            var best = Object.keys(d.scores).sort(function (a, b) {
                return d.scores[b] - d.scores[a];
            })[0];
            if (best) setActive(best);
        }
        openPin = slug;
        Object.keys(pins).forEach(function (k) {
            pins[k].classList.toggle("is-open", k === slug);
            pins[k].setAttribute(
                "aria-expanded",
                k === slug ? "true" : "false",
            );
        });
        renderPop(slug);
    }

    function renderPop(slug) {
        var d = destBySlug[slug];
        popEl.hidden = false;
        popEl.setAttribute("data-side", d.x > 42 ? "left" : "right");
        popEl.innerHTML = "";

        var media = el("div", "tvx-pop__media");
        if (d.image) {
            var img = el("img");
            img.src = d.image;
            img.alt = "";
            img.loading = "lazy";
            media.appendChild(img);
        }
        var close = el("button", "tvx-pop__close");
        close.type = "button";
        close.setAttribute("aria-label", "Đóng");
        close.innerHTML = "&times;";
        close.addEventListener("click", closePop);
        media.appendChild(close);
        media.appendChild(
            el("span", "tvx-pop__score", "Độ hợp " + (d.scores[active] || 0)),
        );
        popEl.appendChild(media);

        var body = el("div", "tvx-pop__body");
        body.appendChild(
            el("p", "tvx-pop__place", d.province + " · " + d.domainName),
        );
        body.appendChild(el("h3", "tvx-pop__name", d.name));
        body.appendChild(el("p", "tvx-pop__desc", d.description));

        var others = Object.keys(d.scores)
            .filter(function (k) {
                return k !== active && bySlug[k];
            })
            .sort(function (a, b) {
                return d.scores[b] - d.scores[a];
            })
            .slice(0, 3);
        if (others.length) {
            var row = el("div", "tvx-pop__also");
            row.appendChild(el("span", null, "Cũng hợp với"));
            others.forEach(function (k) {
                var c = el("button", "tvx-chip", bySlug[k].name);
                c.type = "button";
                c.addEventListener("click", function () {
                    stopAuto();
                    setActive(k);
                });
                row.appendChild(c);
            });
            body.appendChild(row);
        }

        if (d.tourUrl) {
            var a = el("a", "tvx-pop__cta");
            a.href = d.tourUrl;
            a.innerHTML =
                d.tourCount +
                ' tour đang mở bán <i class="fal fa-arrow-right"></i>';
            body.appendChild(a);
        } else {
            body.appendChild(el("p", "tvx-pop__soon", "Tour sắp có"));
        }
        popEl.appendChild(body);

        // điện thoại/máy tính bảng: thẻ nằm dưới bản đồ, đưa vào tầm nhìn
        if (
            window.matchMedia &&
            window.matchMedia("(max-width: 991px)").matches
        ) {
            popEl.scrollIntoView({
                block: "nearest",
                behavior: reduced ? "auto" : "smooth",
            });
        }
    }

    function closePop() {
        openPin = null;
        popEl.hidden = true;
        Object.keys(pins).forEach(function (k) {
            pins[k].classList.remove("is-open");
            pins[k].setAttribute("aria-expanded", "false");
        });
    }

    /* ---------- Tab ---------- */
    tabs.forEach(function (t, idx) {
        t.addEventListener("click", function (e) {
            e.preventDefault();
            stopAuto();
            setActive(t.getAttribute("data-slug"));
        });
        t.addEventListener("keydown", function (e) {
            var n = null;
            if (e.key === "ArrowDown" || e.key === "ArrowRight")
                n = tabs[(idx + 1) % tabs.length];
            if (e.key === "ArrowUp" || e.key === "ArrowLeft")
                n = tabs[(idx - 1 + tabs.length) % tabs.length];
            if (n) {
                e.preventDefault();
                n.focus();
                stopAuto();
                setActive(n.getAttribute("data-slug"));
            }
        });
    });

    document.addEventListener("click", function (e) {
        if (openPin && !popEl.contains(e.target)) closePop();
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && openPin) closePop();
    });
    root.addEventListener("pointerdown", stopAuto);
    root.addEventListener("focusin", stopAuto);
    window.addEventListener("resize", function () {
        requestAnimationFrame(avoidLabelCollisions);
    });

    /* ---------- Tự chạy nhẹ cho tới khi người dùng chạm vào ---------- */
    function startAuto() {
        if (reduced || interacted || timer) return;
        timer = setInterval(function () {
            if (document.hidden || openPin) return;
            var i = tabs.findIndex(function (t) {
                return t.getAttribute("data-slug") === active;
            });
            setActive(tabs[(i + 1) % tabs.length].getAttribute("data-slug"));
        }, 6500);
    }
    function stopAuto() {
        interacted = true;
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    setActive(active);

    if ("IntersectionObserver" in window) {
        var io = new IntersectionObserver(
            function (entries) {
                entries.forEach(function (en) {
                    if (en.isIntersecting) startAuto();
                    else if (timer) {
                        clearInterval(timer);
                        timer = null;
                    }
                });
            },
            { threshold: 0.4 },
        );
        io.observe(root);
    } else {
        startAuto();
    }
})();
