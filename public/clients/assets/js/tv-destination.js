/* tv-destination.js — trang "Điểm đến": danh sách điểm đến + bản đồ cảm xúc.
   Đọc JSON trong #tvpData (do InspirationService::mapData() tạo, truyền qua DestinationController).
   Vẽ bản đồ từ <template id="tvpSvg"> (chính là partial vn-map-svg dùng ở Home).
   Không phụ thuộc thư viện. */
(function () {
    "use strict";

    var root = document.getElementById("tvpDest");
    var dataEl = document.getElementById("tvpData");
    var svgTpl = document.getElementById("tvpSvg");
    if (!root || !dataEl || !svgTpl) return;

    var DATA;
    try {
        DATA = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }
    var INS = DATA.inspirations || [];
    var DEST = DATA.destinations || [];
    if (!INS.length || !DEST.length) return;

    var ART = root.getAttribute("data-art") || ""; // thư mục ảnh minh họa dự phòng, có dấu / ở cuối

    /* ---------- cấu hình có thể chỉnh ---------- */
    // Điểm đến coi là "Hải đảo" (DB chưa có cột này nên khai báo ở đây)
    var ISLANDS = ["ly-son", "phu-quoc", "con-dao", "cat-ba", "ha-long"];
    // Thứ tự ưu tiên ở tab "Tất cả" (slug trong tbl_destinations); điểm khác xếp sau theo điểm cao nhất
    var FEATURED = [
        "phu-yen",
        "da-nang",
        "ha-giang",
        "phu-quoc",
        "ha-long",
        "hoi-an",
        "sa-pa",
        "ninh-binh",
    ];
    var PAGE = 8; // số thẻ mỗi lần hiển thị
    // ảnh minh họa dự phòng khi điểm đến chưa có coverImage (file trong images/illustrations/)
    var FALLBACK = {
        bien: "scene-bay.svg",
        "thien-nhien": "scene-mountain.svg",
        "chua-lanh": "scene-terrace.svg",
        "van-hoa": "scene-city.svg",
        "am-thuc": "scene-city.svg",
        "mien-que": "scene-terrace.svg",
        "san-anh": "scene-mountain.svg",
        "hai-nguoi": "scene-bay.svg",
    };
    var REGIONS = [
        {
            k: "b",
            name: "Miền Bắc",
            sub: "Hà Giang, Sa Pa, Hà Nội...",
            art: "scene-mountain.svg",
        },
        {
            k: "t",
            name: "Miền Trung",
            sub: "Huế, Đà Nẵng, Hội An...",
            art: "scene-bridge.svg",
        },
        {
            k: "n",
            name: "Miền Nam",
            sub: "Phú Quốc, Cần Thơ, Côn Đảo...",
            art: "scene-island.svg",
        },
        {
            k: "is",
            name: "Hải đảo",
            sub: "Phú Quốc, Lý Sơn, Côn Đảo...",
            art: "scene-bay.svg",
        },
    ];

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
    function $(id) {
        return document.getElementById(id);
    }
    function norm(t) {
        return String(t)
            .toLowerCase()
            .normalize("NFD")
            .replace(/[̀-ͯ]/g, "")
            .replace(/đ/g, "d");
    }
    function icon(cls) {
        return '<i class="fas ' + esc(cls) + '" aria-hidden="true"></i>';
    }
    var SVG_ARROW =
        '<svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
    var SVG_HEART =
        '<svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-8-5-8-11a4.5 4.5 0 018-2.5A4.5 4.5 0 0120 9c0 6-8 11-8 11z"/></svg>';
    var SVG_X =
        '<svg class="tvp-ic" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';

    var insBy = {};
    INS.forEach(function (i) {
        insBy[i.slug] = i;
    });
    var destBy = {};
    DEST.forEach(function (d) {
        destBy[d.slug] = d;
    });

    function best(d) {
        return Object.keys(d.scores).sort(function (a, b) {
            return d.scores[b] - d.scores[a];
        });
    }
    function topScore(d) {
        var b = best(d)[0];
        return b ? d.scores[b] : 0;
    }
    function isIsland(d) {
        return ISLANDS.indexOf(d.slug) > -1;
    }
    function imgOf(d) {
        if (d.image) return d.image;
        var b = best(d)[0];
        return (
            ART +
            (d.slug === "da-nang"
                ? "scene-bridge.svg"
                : isIsland(d) && d.slug !== "ha-long"
                  ? "scene-island.svg"
                  : FALLBACK[b] || "scene-bay.svg")
        );
    }
    function linkOf(d) {
        return (
            d.url ||
            d.tourUrl ||
            DATA.exploreUrl +
                "?inspiration=" +
                encodeURIComponent(best(d)[0] || INS[0].slug)
        );
    }

    /* ---------- trạng thái ---------- */
    var S = { ins: "all", reg: "all", q: "", lim: PAGE, open: null, saved: {} };
    try {
        S.saved = JSON.parse(localStorage.getItem("tvp-saved") || "{}") || {};
    } catch (e) {}

    /* đọc bộ lọc từ URL: /destination?q=phu+yen&ins=bien&reg=t */
    (function () {
        var p = new URLSearchParams(location.search);
        var q = (p.get("q") || "").slice(0, 80);
        if (q) {
            S.q = q;
            $("tvpQ").value = q;
        }
        if (insBy[p.get("ins")]) S.ins = p.get("ins");
        var r = p.get("reg");
        if (
            REGIONS.some(function (x) {
                return x.k === r;
            })
        )
            S.reg = r;
    })();
    function syncUrl() {
        var p = new URLSearchParams();
        if (S.q.trim()) p.set("q", S.q.trim());
        if (S.ins !== "all") p.set("ins", S.ins);
        if (S.reg !== "all") p.set("reg", S.reg);
        var s = p.toString();
        history.replaceState(null, "", location.pathname + (s ? "?" + s : ""));
    }

    function mapIns() {
        return S.ins === "all" ? INS[0].slug : S.ins;
    }
    function inReg(d) {
        return (
            S.reg === "all" ||
            (S.reg === "is" ? isIsland(d) : d.domain === S.reg)
        );
    }
    function list() {
        var q = norm(S.q.trim());
        var l = DEST.filter(function (d) {
            return (
                inReg(d) &&
                (S.ins === "all" || (d.scores[S.ins] || 0) > 0) &&
                (!q ||
                    norm(
                        d.name + " " + d.province + " " + d.description,
                    ).indexOf(q) > -1)
            );
        });
        if (S.ins === "all") {
            l.sort(function (a, b) {
                var fa = FEATURED.indexOf(a.slug),
                    fb = FEATURED.indexOf(b.slug);
                if (fa < 0) fa = 99;
                if (fb < 0) fb = 99;
                return fa - fb || topScore(b) - topScore(a);
            });
        } else {
            l.sort(function (a, b) {
                return b.scores[S.ins] - a.scores[S.ins];
            });
        }
        return l;
    }

    /* ---------- thanh lọc + tab bản đồ ---------- */
    var chips = $("tvpChips"),
        mtabs = $("tvpTabs");
    function tabs() {
        var all = [{ slug: "all", name: "Tất cả", icon: "fa-th-large" }].concat(
            INS,
        );
        chips.innerHTML = all
            .map(function (c) {
                return (
                    '<button type="button" class="tvp-chip' +
                    (S.ins === c.slug ? " on" : "") +
                    '" aria-pressed="' +
                    (S.ins === c.slug) +
                    '" data-k="' +
                    esc(c.slug) +
                    '">' +
                    icon(c.icon) +
                    esc(c.name) +
                    "</button>"
                );
            })
            .join("");
        mtabs.innerHTML = INS.map(function (i) {
            return (
                '<button type="button" class="tvp-mtab" aria-pressed="' +
                (mapIns() === i.slug) +
                '" data-k="' +
                esc(i.slug) +
                '">' +
                icon(i.icon) +
                esc(i.name) +
                "</button>"
            );
        }).join("");
    }
    function setIns(k) {
        S.ins = k;
        S.lim = PAGE;
        S.open = null;
        tabs();
        drawGrid();
        drawMap();
    }
    chips.addEventListener("click", function (e) {
        var b = e.target.closest(".tvp-chip");
        if (b) setIns(b.dataset.k);
    });
    mtabs.addEventListener("click", function (e) {
        var b = e.target.closest(".tvp-mtab");
        if (b) setIns(b.dataset.k);
    });

    /* ---------- lưới điểm đến ---------- */
    var grid = $("tvpGrid");
    function drawGrid() {
        syncUrl();
        var l = list(),
            show = l.slice(0, S.lim);
        var filtered = !(S.ins === "all" && S.reg === "all" && !S.q);
        $("tvpTitle").textContent =
            S.ins === "all"
                ? "Điểm đến nổi bật"
                : 'Hợp với "' + insBy[S.ins].name + '"';
        $("tvpCount").textContent =
            (filtered
                ? ""
                : "Những địa điểm không thể bỏ lỡ trong hành trình khám phá Việt Nam. ") +
            "Hiển thị " +
            show.length +
            " / " +
            l.length +
            " điểm đến";
        $("tvpReset").hidden = !filtered;
        $("tvpMore").hidden = l.length <= S.lim;

        if (!show.length) {
            var sug = INS.slice(0, 4)
                .map(function (i) {
                    return (
                        '<button type="button" data-ins="' +
                        esc(i.slug) +
                        '">' +
                        esc(i.name) +
                        "</button>"
                    );
                })
                .join("");
            grid.innerHTML =
                '<div class="tvp-empty"><p>Không tìm thấy điểm đến nào' +
                (S.q ? " cho “" + esc(S.q) + "”" : "") +
                ". Thử một cảm hứng khác:</p>" +
                '<div class="tvp-empty__sug">' +
                sug +
                "</div>" +
                '<button type="button" class="tvp-btn tvp-btn--teal" data-reset>Xóa bộ lọc</button></div>';
            grid.removeAttribute("aria-busy");
            return;
        }
        grid.innerHTML = show
            .map(function (d) {
                var b = best(d),
                    sel = S.ins !== "all" ? S.ins : null;
                var tags = sel
                    ? [sel].concat(
                          b
                              .filter(function (x) {
                                  return x !== sel;
                              })
                              .slice(0, 1),
                      )
                    : b.slice(0, 2);
                return (
                    '<article class="tvp-dcard tvp-rv">' +
                    '<div class="tvp-dcard__img"><img src="' +
                    esc(imgOf(d)) +
                    '" alt="" loading="lazy" decoding="async">' +
                    (d.tourCount
                        ? '<span class="tvp-badge">' +
                          d.tourCount +
                          " tour</span>"
                        : "") +
                    '<button type="button" class="tvp-heart" aria-label="' +
                    (S.saved[d.slug] ? "Bỏ lưu " : "Lưu ") +
                    esc(d.name) +
                    '" aria-pressed="' +
                    !!S.saved[d.slug] +
                    '" data-s="' +
                    esc(d.slug) +
                    '">' +
                    SVG_HEART +
                    "</button></div>" +
                    '<div class="tvp-dcard__body"><h3>' +
                    esc(d.name) +
                    SVG_ARROW +
                    "</h3>" +
                    '<div class="tvp-dcard__pv">' +
                    esc(d.province) +
                    "</div><p>" +
                    esc(d.description) +
                    "</p>" +
                    '<div class="tvp-fit">' +
                    tags
                        .map(function (s) {
                            return (
                                '<span class="tvp-mtag' +
                                (s === sel ? " on" : "") +
                                '">' +
                                icon(insBy[s].icon) +
                                esc(insBy[s].name) +
                                " <em>" +
                                d.scores[s] +
                                "</em></span>"
                            );
                        })
                        .join("") +
                    "</div></div>" +
                    '<a class="tvp-dlink" href="' +
                    esc(linkOf(d)) +
                    '" aria-label="Xem ' +
                    esc(d.name) +
                    '"></a></article>'
                );
            })
            .join("");
    }
    grid.removeAttribute("aria-busy");
    grid.addEventListener("click", function (e) {
        var h = e.target.closest(".tvp-heart");
        if (h) {
            var s = h.dataset.s;
            S.saved[s] = !S.saved[s];
            h.setAttribute("aria-pressed", !!S.saved[s]);
            h.setAttribute(
                "aria-label",
                (S.saved[s] ? "Bỏ lưu " : "Lưu ") + destBy[s].name,
            );
            try {
                localStorage.setItem("tvp-saved", JSON.stringify(S.saved));
            } catch (x) {}
            return;
        }
        var si = e.target.closest("[data-ins]");
        if (si) {
            S.reg = "all";
            S.q = "";
            $("tvpQ").value = "";
            setIns(si.dataset.ins);
            drawReg();
            return;
        }
        if (e.target.closest("[data-reset]")) reset();
    });
    function reset() {
        S.ins = "all";
        S.reg = "all";
        S.q = "";
        S.lim = PAGE;
        $("tvpQ").value = "";
        tabs();
        drawGrid();
        drawMap();
        drawReg();
    }
    $("tvpReset").addEventListener("click", reset);
    $("tvpMore").addEventListener("click", function () {
        var before = grid.querySelectorAll(".tvp-dcard").length;
        S.lim += PAGE;
        drawGrid();
        var first = grid.querySelectorAll(".tvp-dlink")[before];
        if (first) first.focus({ preventScroll: true });
    });
    var qt;
    $("tvpQ").addEventListener("input", function (e) {
        clearTimeout(qt);
        var v = e.target.value;
        qt = setTimeout(function () {
            S.q = v;
            S.lim = PAGE;
            drawGrid();
            drawMap();
        }, 160);
    });
    $("tvpSearch").addEventListener("submit", function (e) {
        e.preventDefault();
        S.q = $("tvpQ").value;
        S.lim = PAGE;
        drawGrid();
        drawMap();
        $("tvpTitle").scrollIntoView({ behavior: "smooth", block: "start" });
    });

    /* ---------- bản đồ ---------- */
    var wrap = $("tvpVn"),
        pinsEl = $("tvpPins"),
        pop = $("tvpPop"),
        board = $("tvpBoard");
    var svgHtml = svgTpl.innerHTML
        .trim()
        .replace(
            /<svg([^>]*)>/,
            '<svg$1><defs><linearGradient id="tvpLand" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#d9f3ec"/><stop offset=".55" stop-color="#8ed8cd"/><stop offset="1" stop-color="#4fbcb4"/></linearGradient><filter id="tvpRoughMap" x="-3%" y="-3%" width="106%" height="106%"><feTurbulence type="fractalNoise" baseFrequency=".035" numOctaves="2" seed="7"/><feDisplacementMap in="SourceGraphic" scale="6"/></filter></defs>',
        )
        .replace(
            "</svg>",
            '<path id="tvpRoute" class="tvp-route" d=""/></svg>',
        );
    wrap.insertAdjacentHTML("afterbegin", svgHtml);

    var pinEls = {};
    DEST.forEach(function (d, i) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "tvp-mp" + (d.x > 46 ? "" : " west");
        b.style.left = d.x + "%";
        b.style.top = d.y + "%";
        b.style.setProperty("--i", i % 12);
        b.dataset.s = d.slug;
        b.setAttribute("aria-label", d.name + ", " + d.province);
        b.innerHTML =
            '<span class="d"></span><span class="l">' + esc(d.name) + "</span>";
        pinsEl.appendChild(b);
        pinEls[d.slug] = b;
    });
    pinsEl.addEventListener("click", function (e) {
        var b = e.target.closest(".tvp-mp");
        if (b) {
            e.stopPropagation();
            openPin(b.dataset.s);
        }
    });

    function drawMap() {
        var k = mapIns(),
            ins = insBy[k];
        var lit = DEST.filter(function (d) {
            return d.scores[k];
        }).sort(function (a, b) {
            return b.scores[k] - a.scores[k];
        });
        var q = norm(S.q.trim());
        DEST.forEach(function (d) {
            var s = d.scores[k] || 0,
                b = pinEls[d.slug];
            b.classList.toggle("lit", s > 0);
            b.classList.toggle("top", s >= 85);
            b.classList.toggle(
                "dim",
                !inReg(d) || (!!q && norm(d.name + d.province).indexOf(q) < 0),
            );
            b.classList.remove("show");
            b.style.setProperty(
                "--k",
                s ? (0.7 + Math.max(0, s - 40) / 60).toFixed(2) : 0.55,
            );
            b.style.zIndex = s ? String(10 + s) : 1;
            b.tabIndex = s ? 0 : -1;
        });
        lit.filter(inReg)
            .slice(0, 5)
            .forEach(function (d) {
                pinEls[d.slug].classList.add("show");
            });
        requestAnimationFrame(function () {
            var seen = [];
            pinsEl.querySelectorAll(".tvp-mp.show").forEach(function (b) {
                var r = b.querySelector(".l").getBoundingClientRect();
                var hit = seen.some(function (o) {
                    return !(
                        r.right < o.left - 4 ||
                        r.left > o.right + 4 ||
                        r.bottom < o.top - 2 ||
                        r.top > o.bottom + 2
                    );
                });
                if (hit) b.classList.remove("show");
                else seen.push(r);
            });
        });
        $("tvpHead").textContent = ins.headline || "";
        $("tvpLit").textContent = lit.length;
        var top = $("tvpTop");
        top.innerHTML = "";
        lit.slice(0, 3).forEach(function (d) {
            var a = document.createElement("button");
            a.type = "button";
            a.className = "tvp-linkbtn";
            a.textContent = d.name;
            a.onclick = function () {
                openPin(d.slug);
            };
            top.appendChild(a);
        });
        var cta = $("tvpCta");
        cta.innerHTML = "Xem " + lit.length + " điểm đến " + SVG_ARROW;
        cta.setAttribute(
            "href",
            DATA.exploreUrl + "?inspiration=" + encodeURIComponent(k),
        );

        // đường đi: nối 4 điểm hợp nhất từ Bắc xuống Nam (toạ độ SVG 800 x 952.8)
        var pts = lit
                .slice(0, 4)
                .sort(function (a, b) {
                    return a.y - b.y;
                })
                .map(function (d) {
                    return [d.x * 8, d.y * 9.528];
                }),
            dd = "";
        pts.forEach(function (q2, i) {
            if (!i) {
                dd = "M" + q2[0] + " " + q2[1];
                return;
            }
            var a = pts[i - 1];
            dd +=
                " Q" +
                ((a[0] + q2[0]) / 2 + (i % 2 ? -45 : 45)) +
                " " +
                (a[1] + q2[1]) / 2 +
                " " +
                q2[0] +
                " " +
                q2[1];
        });
        var rt = $("tvpRoute");
        rt.setAttribute("d", dd);
        rt.classList.remove("draw");
        void rt.getBoundingClientRect();
        rt.classList.add("draw");

        if (S.open) renderPop(S.open);
        else pop.hidden = true;
    }

    function openPin(slug) {
        var d = destBy[slug];
        if (!d) return;
        if (!d.scores[mapIns()] && best(d)[0]) {
            S.ins = best(d)[0];
            tabs();
            drawGrid();
            drawMap();
        }
        S.open = slug;
        Object.keys(pinEls).forEach(function (s) {
            pinEls[s].classList.toggle("open", s === slug);
        });
        renderPop(slug);
        if (window.innerWidth < 761)
            board.scrollIntoView({ behavior: "smooth", block: "center" });
    }
    function renderPop(slug) {
        var d = destBy[slug],
            b = best(d).slice(0, 3);
        pop.hidden = false;
        pop.innerHTML =
            '<div class="tvp-mpop__im"><img src="' +
            esc(imgOf(d)) +
            '" alt=""><button type="button" class="tvp-mpop__x" aria-label="Đóng">' +
            SVG_X +
            "</button></div>" +
            '<div class="tvp-mpop__bd"><h4>' +
            esc(d.name) +
            "</h4><small>" +
            esc(d.province) +
            "</small><p>" +
            esc(d.description) +
            "</p>" +
            '<div class="tvp-bars">' +
            b
                .map(function (s) {
                    return (
                        '<div class="tvp-bar"><b>' +
                        esc(insBy[s].name) +
                        '</b><span><i style="width:' +
                        d.scores[s] +
                        '%"></i></span><em>' +
                        d.scores[s] +
                        "</em></div>"
                    );
                })
                .join("") +
            "</div>" +
            '<div class="tvp-mpop__go"><span>' +
            (d.tourCount ? d.tourCount + " tour đang mở" : "Chưa có tour") +
            '</span><a href="' +
            esc(linkOf(d)) +
            '">Xem ' +
            SVG_ARROW +
            "</a></div></div>";
        if (window.innerWidth > 760) {
            var left = d.x < 50;
            pop.style.left = left ? "auto" : "8px";
            pop.style.right = left ? "8px" : "auto";
            pop.style.top =
                Math.max(
                    10,
                    Math.min(
                        (d.y / 100) * wrap.clientHeight - 60,
                        wrap.clientHeight - 250,
                    ),
                ) + "px";
        }
        pop.querySelector(".tvp-mpop__x").onclick = closePop;
    }
    function closePop() {
        S.open = null;
        pop.hidden = true;
        Object.keys(pinEls).forEach(function (s) {
            pinEls[s].classList.remove("open");
        });
    }
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && S.open) closePop();
    });

    /* ---------- thẻ vùng miền ---------- */
    var rl = $("tvpRegions");
    function inRegion(d, k) {
        return k === "is" ? isIsland(d) : d.domain === k;
    }
    function drawReg() {
        rl.innerHTML = REGIONS.map(function (r) {
            var ds = DEST.filter(function (d) {
                return inRegion(d, r.k);
            });
            var withImg = ds.filter(function (d) {
                return d.image;
            })[0];
            return (
                '<button type="button" class="tvp-reg tvp-rv" data-r="' +
                r.k +
                '" aria-pressed="' +
                (S.reg === r.k) +
                '">' +
                '<div class="tvp-reg__t"><img src="' +
                esc(withImg ? withImg.image : ART + r.art) +
                '" alt="" loading="lazy"></div>' +
                "<div><h4>" +
                r.name +
                "</h4><small>" +
                r.sub +
                '</small><span class="n">' +
                ds.length +
                " điểm đến</span></div>" +
                SVG_ARROW +
                "</button>"
            );
        }).join("");
    }
    function hlPins(k) {
        DEST.forEach(function (d) {
            pinEls[d.slug].classList.toggle("hl", !!k && inRegion(d, k));
        });
    }
    rl.addEventListener("click", function (e) {
        var b = e.target.closest(".tvp-reg");
        if (!b) return;
        S.reg = S.reg === b.dataset.r ? "all" : b.dataset.r;
        S.lim = PAGE;
        closePop();
        drawReg();
        drawGrid();
        drawMap();
    });
    rl.addEventListener("mouseover", function (e) {
        var b = e.target.closest(".tvp-reg");
        if (b) hlPins(b.dataset.r);
    });
    rl.addEventListener("mouseout", function () {
        hlPins(null);
    });

    tabs();
    drawGrid();
    drawReg();
    drawMap();
})();
