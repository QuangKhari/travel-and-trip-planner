/* "Khám phá theo cảm hứng" — hub: mục lục (rê chuột xem nhanh, bấm để chọn), ảnh hero,
   bản đồ mini, bản đồ màu nước, điểm đến gợi ý.
   Dữ liệu: <script id="tvhData"> (App\Services\InspirationService::mapData()). Không phụ thuộc thư viện. */
(function () {
    "use strict";

    var dataEl = document.getElementById("tvhData");
    var hub = document.getElementById("tvhHub");
    if (!dataEl || !hub) {
        return;
    }

    var data;
    try {
        data = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }
    var INS = data.inspirations || [];
    var DEST = data.destinations || [];
    if (!INS.length) {
        return;
    }

    var $ = function (id) {
        return document.getElementById(id);
    };
    var els = {
        toc: $("tvhToc"),
        hero: $("tvhHero"),
        heroImg: $("tvhHeroImg"),
        p1: $("tvhHeroP1"),
        p2: $("tvhHeroP2"),
        quote: $("tvhQuote"),
        badgeIcon: $("tvhBadgeIcon"),
        title: $("tvhHeroTitle"),
        tag: $("tvhHeroTag"),
        desc: $("tvhHeroDesc"),
        cta: $("tvhCta"),
        ctaText: $("tvhCtaText"),
        pager: $("tvhPager"),
        prev: $("tvhPrev"),
        next: $("tvhNext"),
        thumbs: $("tvhThumbs"),
        mini: $("tvhMini"),
        miniDots: $("tvhMiniDots"),
        miniName: $("tvhMiniName"),
        miniCount: $("tvhMiniCount"),
        mapIns: $("tvhMapIns"),
        nowIcon: $("tvhNowIcon"),
        nowName: $("tvhNowName"),
        nowTag: $("tvhNowTag"),
        doms: $("tvhDoms"),
        board: $("tvhBoard"),
        pins: $("tvhPins"),
        labels: $("tvhLabels"),
        lit: $("tvhLit"),
        bandSub: $("tvhBandSub"),
        band: $("tvhBand"),
        dWhere: $("tvhDetailWhere"),
        dName: $("tvhDetailName"),
        dDesc: $("tvhDetailDesc"),
        dCta: $("tvhDetailCta"),
        dSoon: $("tvhDetailSoon"),
        dWx: $("tvhDetailWx"),
        dMore: $("tvhDetailMore"),
        all: $("tvhAll"),
        allText: $("tvhAllText"),
        live: $("tvhLive"),
        banDo: $("tvhBanDo"),
    };

    var DOMNAME = { b: "Miền Bắc", t: "Miền Trung", n: "Miền Nam" };
    var SHAPES = {
        waves: [
            "M0 176 Q100 150 200 176 T400 176 T600 176 T800 176 V480 H0Z",
            "M0 206 Q100 184 200 206 T400 206 T600 206 T800 206 V480 H0Z",
        ],
        mountains: [
            "M0 190 L140 70 L260 170 L420 40 L580 170 L700 100 L800 150 V480 H0Z",
            "M0 214 L180 160 L330 205 L520 150 L800 205 V480 H0Z",
        ],
        hills: [
            "M0 170 C120 110 260 120 380 165 S650 120 800 160 V480 H0Z",
            "M0 210 C140 170 280 190 400 208 S680 180 800 200 V480 H0Z",
        ],
        terraces: [
            "M0 150 C140 130 260 165 400 145 S660 150 800 135 V480 H0Z",
            "M0 195 C140 175 260 205 400 190 S660 195 800 180 V480 H0Z",
        ],
    };
    var SHAPE_OF = {
        bien: "waves",
        "thien-nhien": "mountains",
        "chua-lanh": "hills",
        "van-hoa": "terraces",
        "am-thuc": "hills",
        "mien-que": "terraces",
        "san-anh": "mountains",
        "hai-nguoi": "hills",
    };

    var mqCompact = window.matchMedia("(max-width: 767.98px)");
    var mqHover = window.matchMedia("(hover: hover) and (min-width: 992px)");
    var mqMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

    var state = { ins: INS[0].slug, dom: "all", sel: null, hov: null };
    var timer = null;
    var rows = [].slice.call(els.toc.querySelectorAll(".tvh-row"));

    /* ---------- tiện ích ---------- */
    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            }[c];
        });
    }
    function insBy(slug) {
        for (var i = 0; i < INS.length; i++) {
            if (INS[i].slug === slug) {
                return INS[i];
            }
        }
        return INS[0];
    }
    function destBy(slug) {
        for (var i = 0; i < DEST.length; i++) {
            if (DEST[i].slug === slug) {
                return DEST[i];
            }
        }
        return null;
    }
    function score(d, slug) {
        return (d.scores && d.scores[slug]) || 0;
    }
    function ranked(slug, dom) {
        return DEST.filter(function (d) {
            return (dom === "all" || d.domain === dom) && score(d, slug) > 0;
        }).sort(function (a, b) {
            return (
                score(b, slug) - score(a, slug) ||
                a.name.localeCompare(b.name, "vi")
            );
        });
    }
    function setTimer(fn, ms) {
        clearTimeout(timer);
        timer = setTimeout(fn, ms);
    }
    function scene(kind) {
        if (kind === "card") {
            return (
                '<svg viewBox="0 0 400 104" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false">' +
                '<circle cx="300" cy="28" r="14" fill="#FBE7A8"/>' +
                '<path class="tvh-fa" opacity=".65" d="M0 66 C80 40 170 50 230 64 S350 42 400 58 V104 H0Z"/>' +
                '<path class="tvh-fb" d="M0 86 C90 68 200 78 260 88 S360 72 400 80 V104 H0Z"/></svg>'
            );
        }
        return (
            '<svg viewBox="0 0 170 110" preserveAspectRatio="xMidYMax slice" aria-hidden="true" focusable="false">' +
            '<circle cx="140" cy="26" r="11" fill="#FBE7A8"/>' +
            '<path class="tvh-fa" opacity=".7" d="M0 62 C40 38 90 48 130 60 S160 44 170 52 V110 H0Z"/>' +
            '<path class="tvh-fb" d="M0 84 C40 68 100 78 130 86 S160 74 170 80 V110 H0Z"/></svg>'
        );
    }
    function photo(d) {
        return d.image
            ? '<img src="' +
                  esc(d.image) +
                  '" alt="" loading="lazy" decoding="async">'
            : "";
    }
    function toMap() {
        if (els.banDo && els.banDo.scrollIntoView) {
            els.banDo.scrollIntoView({
                behavior: mqMotion.matches ? "auto" : "smooth",
                block: "start",
            });
        }
    }

    /* ---------- dựng sẵn pin + chấm bản đồ mini (một lần) ---------- */
    var pinEls = {},
        dotEls = {};
    DEST.forEach(function (d) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "tvh-pin";
        b.tabIndex = -1;
        b.style.left = d.x + "%";
        b.style.top = d.y + "%";
        b.setAttribute("data-dest", d.slug);
        b.appendChild(document.createElement("i"));
        els.pins.appendChild(b);
        pinEls[d.slug] = b;

        var dot = document.createElement("i");
        dot.style.left = d.x + "%";
        dot.style.top = d.y + "%";
        els.miniDots.appendChild(dot);
        dotEls[d.slug] = dot;
    });

    /* ---------- vẽ từng phần ---------- */
    function renderRows() {
        var open = state.hov || state.ins;
        rows.forEach(function (r) {
            var slug = r.getAttribute("data-slug");
            var isSel = slug === state.ins;
            r.classList.toggle("is-selected", isSel);
            r.classList.toggle("is-open", slug === open);
            if (isSel) {
                r.setAttribute("aria-current", "true");
            } else {
                r.removeAttribute("aria-current");
            }
            r.tabIndex = isSel ? 0 : -1;
        });
    }

    function renderMini() {
        var pv = insBy(state.hov || state.ins);
        var n = 0;
        els.mini.setAttribute("data-ins", pv.slug);
        els.miniName.textContent = pv.name;
        DEST.forEach(function (d) {
            var s = score(d, pv.slug);
            var dot = dotEls[d.slug];
            dot.className = s > 0 ? "is-lit" : "";
            dot.style.setProperty(
                "--d",
                (s > 0 ? Math.round(5 + (Math.max(0, s - 40) / 60) * 5) : 2) +
                    "px",
            );
            if (s > 0) {
                n++;
            }
        });
        els.miniCount.textContent = n;
        els.mini.setAttribute(
            "aria-label",
            "Xem " + n + " điểm đến trên bản đồ",
        );
    }

    function renderHero(ins, pool) {
        var sh = SHAPES[SHAPE_OF[ins.slug] || "hills"];
        els.p1.setAttribute("d", sh[0]);
        els.p2.setAttribute("d", sh[1]);
        if (ins.image) {
            els.heroImg.src = ins.image;
            els.hero.classList.add("has-photo");
        } else {
            els.hero.classList.remove("has-photo");
            els.heroImg.removeAttribute("src");
        }
        els.quote.textContent = ins.quote || ins.headline;
        els.badgeIcon.className = "fas " + ins.icon;
        els.title.textContent = ins.name;
        els.tag.textContent = ins.tagline;
        els.desc.textContent = ins.headline;
        els.ctaText.textContent = "Xem " + pool.length + " nơi trên bản đồ";
        var idx = INS.indexOf(ins) + 1;
        els.pager.textContent =
            (idx < 10 ? "0" : "") +
            idx +
            " / " +
            (INS.length < 10 ? "0" : "") +
            INS.length;
    }

    function renderThumbs(ins, sel) {
        var top = ranked(ins.slug, "all").slice(0, 4);
        els.thumbs.innerHTML = top
            .map(function (d) {
                var s = score(d, ins.slug);
                return (
                    '<button type="button" class="tvh-thumb' +
                    (d.image ? " has-photo" : "") +
                    (d.slug === sel.slug ? " is-on" : "") +
                    '" data-dest="' +
                    esc(d.slug) +
                    '" aria-label="' +
                    esc(d.name + ", " + s + "% phù hợp. Xem trên bản đồ") +
                    '">' +
                    (d.image ? photo(d) : scene("thumb")) +
                    '<span class="tvh-thumb__pct">' +
                    s +
                    "%</span>" +
                    '<span class="tvh-thumb__name">' +
                    esc(d.name) +
                    "</span></button>"
                );
            })
            .join("");
    }

    function renderIntro(ins) {
        els.mapIns.textContent = ins.name;
        els.nowIcon.className = "fas " + ins.icon;
        els.nowName.textContent = ins.name;
        els.nowTag.textContent = ins.tagline;
        [].forEach.call(els.doms.querySelectorAll("button"), function (b) {
            b.setAttribute(
                "aria-pressed",
                b.getAttribute("data-dom") === state.dom ? "true" : "false",
            );
        });
    }

    function renderPins(ins, sel) {
        DEST.forEach(function (d) {
            var s = score(d, ins.slug);
            var visible = state.dom === "all" || d.domain === state.dom;
            var lit = s > 0 && visible;
            var isSel = d.slug === sel.slug;
            var size = lit
                ? Math.round(14 + (Math.max(0, s - 40) / 60) * 16)
                : 8;
            var b = pinEls[d.slug];
            b.className =
                "tvh-pin" +
                (lit ? " is-lit" : "") +
                (isSel ? " is-sel" : "") +
                (visible ? "" : " is-hidden");
            b.style.setProperty("--d", (isSel ? size + 4 : size) + "px");
            b.style.zIndex = isSel ? 50 : lit ? Math.round(10 + s / 10) : 1;
            b.setAttribute(
                "aria-label",
                d.name +
                    ", " +
                    d.province +
                    (lit ? ", " + s + "% phù hợp" : ""),
            );
        });
    }

    function renderLabels(ins, sel, pool) {
        var rect = els.board.getBoundingClientRect();
        var W = rect.width,
            H = rect.height;
        var max = mqCompact.matches ? 1 : 4;
        var cand = [sel]
            .concat(
                pool
                    .filter(function (d) {
                        return d.slug !== sel.slug;
                    })
                    .slice(0, max - 1),
            )
            .slice(0, max);
        var taken = [],
            html = "";
        cand.forEach(function (d) {
            var px = (d.x / 100) * W,
                py = (d.y / 100) * H;
            var w = Math.max(d.name.length * 7.6, 84) + 24,
                h = 42,
                pinR = 16;
            function mk(side) {
                var x0 = side === "r" ? px + pinR : px - pinR - w;
                return {
                    x0: x0,
                    y0: py - h / 2,
                    x1: x0 + w,
                    y1: py + h / 2,
                    side: side,
                };
            }
            function ok(r) {
                if (r.x0 < 4 || r.x1 > W - 4) {
                    return false;
                }
                return !taken.some(function (o) {
                    return !(
                        r.x1 + 4 < o.x0 ||
                        r.x0 - 4 > o.x1 ||
                        r.y1 + 3 < o.y0 ||
                        r.y0 - 3 > o.y1
                    );
                });
            }
            var r = [mk("r"), mk("l")].filter(ok)[0];
            if (!r) {
                return;
            }
            taken.push(r);
            var tf =
                r.side === "r"
                    ? "translate(" + pinR + "px,-50%)"
                    : "translate(calc(-100% - " + pinR + "px),-50%)";
            html +=
                '<span class="tvh-label' +
                (d.slug === sel.slug ? " is-sel" : "") +
                '" style="left:' +
                d.x +
                "%;top:" +
                d.y +
                "%;transform:" +
                tf +
                '">' +
                "<strong>" +
                esc(d.name) +
                "</strong><span>" +
                score(d, ins.slug) +
                "% phù hợp</span></span>";
        });
        els.labels.innerHTML = html;
    }

    function renderBand(ins, sel, pool) {
        els.bandSub.textContent =
            pool.length +
            " điểm đến hợp với cảm hứng của bạn" +
            (state.dom === "all" ? "" : " · " + DOMNAME[state.dom]);
        els.band.innerHTML = pool
            .slice(0, 3)
            .map(function (d) {
                return (
                    '<button type="button" class="tvh-card' +
                    (d.slug === sel.slug ? " is-on" : "") +
                    '" data-dest="' +
                    esc(d.slug) +
                    '" aria-pressed="' +
                    (d.slug === sel.slug ? "true" : "false") +
                    '">' +
                    '<span class="tvh-card__ph">' +
                    (d.image ? photo(d) : scene("card")) +
                    "</span>" +
                    '<span class="tvh-card__tx"><strong>' +
                    esc(d.name) +
                    "</strong><small>" +
                    esc(d.province) +
                    "</small>" +
                    "<em>" +
                    score(d, ins.slug) +
                    "% phù hợp</em></span></button>"
                );
            })
            .join("");
    }

    function renderDetail(ins, sel, pool) {
        els.dWhere.textContent =
            sel.province +
            " · " +
            (sel.domainName || DOMNAME[sel.domain] || "");
        els.dName.textContent = sel.name;
        els.dDesc.textContent = sel.description || "";
        els.dMore.href = sel.url;
        if (sel.weather && sel.weather.temp !== null) {
            els.dWx.innerHTML =
                '<i class="fas ' +
                esc(sel.weather.icon) +
                '" aria-hidden="true"></i> ' +
                esc(String(sel.weather.temp)) +
                "°C · " +
                esc(sel.weather.label);
            els.dWx.hidden = false;
        } else {
            els.dWx.hidden = true;
        }
        var withTour = sel.tourCount > 0 && sel.tourUrl;
        if (withTour) {
            els.dCta.href = sel.tourUrl;
            els.dCta.className = "tvh-btn";
            els.dCta.innerHTML =
                "Xem " +
                sel.tourCount +
                " tour tại " +
                esc(sel.name) +
                ' <i class="fas fa-arrow-right" aria-hidden="true"></i>';
            els.dSoon.hidden = true;
        } else {
            els.dCta.href =
                data.exploreUrl +
                "?inspiration=" +
                encodeURIComponent(ins.slug);
            els.dCta.className = "tvh-btn is-ghost";
            els.dCta.innerHTML =
                'Xem điểm đến cùng cảm hứng <i class="fas fa-arrow-right" aria-hidden="true"></i>';
            els.dSoon.textContent =
                "Chưa có tour tại " + sel.name + " — sắp có.";
            els.dSoon.hidden = false;
        }
        els.all.href =
            data.exploreUrl +
            "?inspiration=" +
            encodeURIComponent(ins.slug) +
            (state.dom === "all" ? "" : "&domain=" + state.dom);
        els.allText.textContent = "Xem tất cả " + pool.length + " điểm đến";
        els.lit.textContent = pool.length;
    }

    function render() {
        var ins = insBy(state.ins);
        var pool = ranked(ins.slug, state.dom);
        var sel = destBy(state.sel);
        if (
            !sel ||
            !pool.some(function (d) {
                return d.slug === sel.slug;
            })
        ) {
            sel = pool[0] || DEST[0];
        }
        if (!sel) {
            return;
        }
        state.sel = sel.slug;

        hub.setAttribute("data-ins", ins.slug);
        renderRows();
        renderHero(ins, pool);
        renderThumbs(ins, sel);
        renderMini();
        renderIntro(ins);
        renderPins(ins, sel);
        renderLabels(ins, sel, pool);
        renderBand(ins, sel, pool);
        renderDetail(ins, sel, pool);
        els.live.textContent =
            "Đã chọn " + ins.name + ": " + pool.length + " điểm đến phù hợp.";
    }

    /* ---------- hành động ---------- */
    function choose(slug) {
        var best = ranked(slug, state.dom)[0];
        state.ins = slug;
        state.hov = null;
        if (best) {
            state.sel = best.slug;
        }
        render();
    }

    function selectDest(slug, scroll) {
        var d = destBy(slug);
        if (!d) {
            return;
        }
        var inPool =
            score(d, state.ins) > 0 &&
            (state.dom === "all" || d.domain === state.dom);
        if (!inPool) {
            // Điểm không thuộc cảm hứng đang chọn: chuyển sang cảm hứng hợp nhất của nó
            var best = Object.keys(d.scores).sort(function (a, b) {
                return d.scores[b] - d.scores[a];
            })[0];
            if (best) {
                state.ins = best;
            }
            if (state.dom !== "all" && d.domain !== state.dom) {
                state.dom = "all";
            }
        }
        state.sel = slug;
        state.hov = null;
        render();
        if (scroll) {
            toMap();
        }
    }

    function setOpen(slug) {
        if (state.hov === slug) {
            return;
        }
        state.hov = slug;
        renderRows();
        renderMini();
    }

    /* ---------- sự kiện ---------- */
    rows.forEach(function (r) {
        var slug = r.getAttribute("data-slug");
        r.addEventListener("mouseenter", function () {
            if (!mqHover.matches) {
                return;
            }
            setTimer(function () {
                setOpen(slug);
            }, 120);
        });
        r.addEventListener("focus", function () {
            setTimer(function () {
                setOpen(slug);
            }, 60);
        });
        r.addEventListener("click", function (e) {
            if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) {
                return;
            }
            e.preventDefault();
            choose(slug);
        });
    });

    els.toc.addEventListener("mouseleave", function () {
        if (!mqHover.matches) {
            return;
        }
        setTimer(function () {
            setOpen(null);
        }, 200);
    });

    els.toc.addEventListener("keydown", function (e) {
        var k = e.key;
        if (
            [
                "ArrowDown",
                "ArrowUp",
                "ArrowLeft",
                "ArrowRight",
                "Home",
                "End",
            ].indexOf(k) < 0
        ) {
            return;
        }
        e.preventDefault();
        var cur = INS.indexOf(insBy(state.hov || state.ins));
        var n =
            k === "ArrowDown" || k === "ArrowRight"
                ? cur + 1
                : k === "ArrowUp" || k === "ArrowLeft"
                  ? cur - 1
                  : k === "Home"
                    ? 0
                    : INS.length - 1;
        n = (n + INS.length) % INS.length;
        setOpen(INS[n].slug);
        rows[n].tabIndex = 0;
        rows[n].focus();
    });

    els.prev.addEventListener("click", function () {
        choose(
            INS[(INS.indexOf(insBy(state.ins)) - 1 + INS.length) % INS.length]
                .slug,
        );
    });
    els.next.addEventListener("click", function () {
        choose(INS[(INS.indexOf(insBy(state.ins)) + 1) % INS.length].slug);
    });

    els.thumbs.addEventListener("click", function (e) {
        var b = e.target.closest("[data-dest]");
        if (b) {
            selectDest(b.getAttribute("data-dest"), true);
        }
    });
    els.band.addEventListener("click", function (e) {
        var b = e.target.closest("[data-dest]");
        if (b) {
            selectDest(b.getAttribute("data-dest"), false);
        }
    });
    els.pins.addEventListener("click", function (e) {
        var b = e.target.closest("[data-dest]");
        if (b) {
            selectDest(b.getAttribute("data-dest"), false);
        }
    });

    els.doms.addEventListener("click", function (e) {
        var b = e.target.closest("[data-dom]");
        if (!b) {
            return;
        }
        state.dom = b.getAttribute("data-dom");
        var best = ranked(state.ins, state.dom)[0];
        if (best) {
            state.sel = best.slug;
        }
        render();
    });

    var resizeTimer = null;
    window.addEventListener("resize", function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            var ins = insBy(state.ins);
            var pool = ranked(ins.slug, state.dom);
            var sel = destBy(state.sel) || pool[0];
            if (sel) {
                renderLabels(ins, sel, pool);
            }
        }, 150);
    });

    /* Bắt đầu: điểm đầu bảng của cảm hứng đầu tiên */
    var first = ranked(state.ins, "all")[0];
    if (first) {
        state.sel = first.slug;
    }
    render();
})();
