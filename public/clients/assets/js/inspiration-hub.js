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
        quote: $("tvhQuote"),
        nowIcon: $("tvhNowIcon"),
        nowName: $("tvhNowName"),
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
        dMore: $("tvhDetailMore"),
        all: $("tvhAll"),
        allText: $("tvhAllText"),
        live: $("tvhLive"),
        banDo: $("tvhBanDo"),
        dPhoto: $("tvmPhoto"),
        dTemp: $("tvhDetailTemp"),
    };
    // Phòng hờ: phần tử nào không có trong trang thì dùng một thẻ div tách rời để hàm vẽ không lỗi.
    // (16/11/2026: đã bỏ hẳn phần vẽ ảnh hero, 4 thẻ nhỏ, bản đồ mini và hộp thời tiết chi tiết — bố cục trang chủ không còn các khối đó.)
    Object.keys(els).forEach(function (k) {
        if (!els[k]) {
            els[k] = document.createElement("div");
        }
    });

    var DOMNAME = { b: "Miền Bắc", t: "Miền Trung", n: "Miền Nam" };
    var mqCompact = window.matchMedia("(max-width: 767.98px)");
    var mqHover = window.matchMedia("(hover: hover) and (min-width: 992px)");
    var mqMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

    var KEY = "tv.inspiration";
    function saved() {
        var q = null;
        try {
            q = new URLSearchParams(location.search).get("ins") || localStorage.getItem(KEY);
        } catch (e) {}
        return q && INS.some(function (i) { return i.slug === q; }) ? q : INS[0].slug;
    }
    var state = { ins: saved(), dom: "all", sel: null, hov: null, last: null };
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

    /* ---------- dựng sẵn pin trên bản đồ (một lần) ---------- */
    var pinEls = {};
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

    function renderIntro(ins) {
        els.quote.textContent = ins.quote || ins.headline;
        els.nowIcon.className = "fas " + ins.icon;
        els.nowName.textContent = ins.name;
        [].forEach.call(els.doms.querySelectorAll("button"), function (b) {
            b.setAttribute(
                "aria-pressed",
                b.getAttribute("data-dom") === state.dom ? "true" : "false",
            );
        });
    }

    function renderPins(ins, sel, pool) {
        var top = (pool || []).slice(0, 4).map(function (d) { return d.slug; });
        var pop = state.last !== ins.slug;
        var k = 0;
        DEST.forEach(function (d) {
            var s = score(d, ins.slug);
            var visible = state.dom === "all" || d.domain === state.dom;
            var lit = s > 0 && visible;
            var isSel = d.slug === sel.slug;
            var size = lit
                ? Math.round(14 + (Math.max(0, s - 40) / 60) * 16)
                : 8;
            var b = pinEls[d.slug];
            var rank = lit ? top.indexOf(d.slug) + 1 : 0;
            b.className =
                "tvh-pin" +
                (lit ? " is-lit" : "") +
                (isSel ? " is-sel" : "") +
                (rank ? " is-top" : "") +
                (lit && pop ? " is-pop" : "") +
                (visible ? "" : " is-hidden");
            b.firstChild.textContent = rank ? String(rank) : "";
            if (lit && pop) {
                b.style.setProperty("--i", String(k++));
            }
            b.style.setProperty("--d", (isSel ? size + 4 : size) + "px");
            b.style.zIndex = isSel ? 50 : lit ? Math.round(10 + s / 10) : 1;
            b.setAttribute(
                "aria-label",
                d.name +
                    ", " +
                    d.province +
                    (lit ? (s >= 85 ? ", đề xuất" : ", phù hợp") : ""),
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
        // vị trí (px) của mọi chấm đang sáng, để nhãn không đè lên chấm khác
        var litPx = DEST.filter(function (o) {
            return (
                score(o, ins.slug) > 0 &&
                (state.dom === "all" || o.domain === state.dom)
            );
        }).map(function (o) {
            return { slug: o.slug, x: (o.x / 100) * W, y: (o.y / 100) * H };
        });
        var taken = [],
            html = "";
        var pinR = 16,
            h = 42;
        cand.forEach(function (d) {
            var px = (d.x / 100) * W,
                py = (d.y / 100) * H;
            var w = Math.max(d.name.length * 7.6, 84) + 24;
            function mk(side) {
                var x0, y0;
                if (side === "r") {
                    x0 = px + pinR;
                    y0 = py - h / 2;
                } else if (side === "l") {
                    x0 = px - pinR - w;
                    y0 = py - h / 2;
                } else if (side === "t") {
                    x0 = px - w / 2;
                    y0 = py - pinR - h - 2;
                } else {
                    x0 = px - w / 2;
                    y0 = py + pinR + 2;
                }
                return { x0: x0, y0: y0, x1: x0 + w, y1: y0 + h, side: side };
            }
            function inside(r) {
                return r.x0 >= 4 && r.x1 <= W - 4 && r.y0 >= 4 && r.y1 <= H - 4;
            }
            function free(r) {
                return (
                    inside(r) &&
                    !taken.some(function (o) {
                        return !(
                            r.x1 + 4 < o.x0 ||
                            r.x0 - 4 > o.x1 ||
                            r.y1 + 3 < o.y0 ||
                            r.y0 - 3 > o.y1
                        );
                    })
                );
            }
            function clear(r) {
                return !litPx.some(function (p) {
                    return (
                        p.slug !== d.slug &&
                        p.x > r.x0 - 10 &&
                        p.x < r.x1 + 10 &&
                        p.y > r.y0 - 10 &&
                        p.y < r.y1 + 10
                    );
                });
            }
            var opts = ["r", "l", "t", "b"].map(mk);
            var r =
                opts.filter(function (o) {
                    return free(o) && clear(o);
                })[0] ||
                opts.filter(free)[0] ||
                (d.slug === sel.slug ? opts.filter(inside)[0] : null);
            if (!r) {
                return;
            }
            taken.push(r);
            var tf = {
                r: "translate(" + pinR + "px,-50%)",
                l: "translate(calc(-100% - " + pinR + "px),-50%)",
                t: "translate(-50%,calc(-100% - " + pinR + "px))",
                b: "translate(-50%," + pinR + "px)",
            }[r.side];
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
                esc(d.province) +
                "</span></span>";
        });
        els.labels.innerHTML = html;
    }

    function renderBand(ins, sel, pool) {
        els.bandSub.textContent =
            pool.length +
            " điểm đến hợp với cảm hứng của bạn" +
            (state.dom === "all" ? "" : " · " + DOMNAME[state.dom]);
        els.band.innerHTML = pool
            .slice(0, 4)
            .map(function (d, i) {
                return (
                    '<button type="button" class="tvh-card' +
                    (d.slug === sel.slug ? " is-on" : "") +
                    '" data-dest="' +
                    esc(d.slug) +
                    '" aria-pressed="' +
                    (d.slug === sel.slug ? "true" : "false") +
                    '">' +
                    '<span class="tvh-card__ph">' +
                    '<span class="tvh-card__n" aria-hidden="true">' +
                    (i + 1) +
                    "</span>" +
                    (d.image ? photo(d) : scene("card")) +
                    "</span>" +
                    '<span class="tvh-card__tx"><strong>' +
                    esc(d.name) +
                    "</strong><small>" +
                    esc(d.province) +
                    "</small>" +
                    "<em>" +
                    (score(d, ins.slug) >= 85 ? "Đề xuất" : "Phù hợp") +
                    "</em></span></button>"
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
        els.dPhoto.style.backgroundImage = sel.image
            ? 'url("' + String(sel.image).replace(/"/g, "%22") + '")'
            : "";
        els.dPhoto.classList.toggle("is-empty", !sel.image);
        if (sel.weather && sel.weather.temp !== null && sel.weather.temp !== undefined) {
            els.dTemp.innerHTML =
                '<i class="fas ' + esc(sel.weather.icon || "fa-sun") + '" aria-hidden="true"></i> ' +
                esc(Math.round(sel.weather.temp)) + "° · " + esc(sel.weather.label || "");
            els.dTemp.hidden = false;
        } else {
            els.dTemp.hidden = true;
        }
        var withTour = sel.tourCount > 0 && sel.tourUrl;
        if (withTour) {
            els.dCta.href = sel.tourUrl;
            els.dCta.className = "tvh-btn";
            els.dCta.innerHTML = "Xem " + sel.tourCount + " tour";
            els.dCta.setAttribute("aria-label", "Xem " + sel.tourCount + " tour tại " + sel.name);
        } else {
            els.dCta.href =
                data.exploreUrl +
                "?inspiration=" +
                encodeURIComponent(ins.slug);
            els.dCta.className = "tvh-btn is-ghost";
            els.dCta.innerHTML =
                'Xem điểm đến cùng cảm hứng <i class="fas fa-arrow-right" aria-hidden="true"></i>';
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
        renderIntro(ins);
        renderPins(ins, sel, pool);
        renderLabels(ins, sel, pool);
        renderBand(ins, sel, pool);
        renderDetail(ins, sel, pool);
        els.live.textContent =
            "Đã chọn " + ins.name + ": " + pool.length + " điểm đến phù hợp.";
        if (state.last !== ins.slug) {
            state.last = ins.slug;
            document.dispatchEvent(
                new CustomEvent("tv:inspiration", {
                    detail: { slug: ins.slug, name: ins.name, icon: ins.icon },
                }),
            );
        }
    }

    /* ---------- hành động ---------- */
    function choose(slug) {
        try {
            localStorage.setItem(KEY, slug);
        } catch (e) {}
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

    function hot(slug) {
        Object.keys(pinEls).forEach(function (k) {
            pinEls[k].classList.toggle("is-hot", k === slug);
        });
        [].forEach.call(els.band.querySelectorAll("[data-dest]"), function (c) {
            c.classList.toggle("is-hot", c.getAttribute("data-dest") === slug);
        });
    }
    els.band.addEventListener("mouseover", function (e) {
        var b = e.target.closest("[data-dest]");
        hot(b ? b.getAttribute("data-dest") : null);
    });
    els.band.addEventListener("mouseleave", function () { hot(null); });
    els.pins.addEventListener("mouseover", function (e) {
        var b = e.target.closest("[data-dest]");
        hot(b ? b.getAttribute("data-dest") : null);
    });
    els.pins.addEventListener("mouseleave", function () { hot(null); });

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
