/* ux-pass.js — hành vi cho các trang đã làm lại: đặt tour, chi tiết đơn, đánh giá sao, yêu thích,
   liên hệ, hồ sơ, chi tiết blog, đăng nhập, thêm vào lịch, kệ tour đã lưu.
   Nạp SAU toastr/jQuery trong footer.blade.php. Mỗi khối tự kiểm tra phần tử có trên trang hay không,
   nên nạp ở mọi trang đều an toàn. */
(function () {
    "use strict";

    function $(s, r) {
        return (r || document).querySelector(s);
    }
    function $$(s, r) {
        return Array.prototype.slice.call((r || document).querySelectorAll(s));
    }
    function money(n) {
        return Math.round(n).toLocaleString("vi-VN") + "đ";
    }
    function toast(type, msg) {
        if (window.toastr && window.toastr[type]) {
            window.toastr[type](msg);
        }
    }

    /* =====================================================================
       1. TRANG ĐẶT TOUR  (#bkForm)
       Lưu ý: tổng tiền ở đây chỉ để HIỂN THỊ. Server luôn tính lại khi đặt.
       ===================================================================== */
    function initBooking() {
        var form = $("#bkForm");
        if (!form) {
            return;
        }

        var priceA = +form.dataset.priceAdult || 0;
        var priceC = +form.dataset.priceChild || 0;
        var limit = +form.dataset.limit || 1;
        var couponUrl = form.dataset.couponUrl;
        var tourId = (form.elements.tourId || {}).value;

        var inAdults = $("#bkAdults"),
            inChildren = $("#bkChildren");
        var outAdults = $("#bkAdultsOut"),
            outChildren = $("#bkChildrenOut");
        var couponIn = $("#bkCoupon"),
            couponBtn = $("#bkCouponBtn");
        var couponMsg = $("#bkCouponMsg"),
            couponRemove = $("#bkCouponRemove");
        var submitBtns = $$("[data-bk-submit]");
        var state = {
            discount: 0,
            couponApplied: false,
            quoting: false,
            quoteTimer: null,
        };

        /* ---------- tính và vẽ lại tóm tắt ---------- */
        function render() {
            var a = +inAdults.value,
                c = +inChildren.value;
            var subA = a * priceA,
                subC = c * priceC;
            var total = Math.max(0, subA + subC - state.discount);

            outAdults.textContent = a;
            outChildren.textContent = c;
            $("#bkAdultsFormula").textContent = a + " × " + money(priceA);
            $("#bkAdultsSum").textContent = money(subA);
            $("#bkChildrenFormula").textContent = c + " × " + money(priceC);
            $("#bkChildrenSum").textContent = money(subC);
            $("#bkRowChildren").hidden = c === 0;

            var rowD = $("#bkRowDiscount");
            rowD.hidden = !(state.couponApplied && state.discount > 0);
            $("#bkDiscountSum").textContent = "-" + money(state.discount);
            $("#bkDiscountLabel").textContent = state.couponApplied
                ? "(mã " + couponIn.value.toUpperCase() + ")"
                : "";

            $("#bkTotal").textContent = money(total);
            $("#bkBarTotal").textContent = money(total);

            // Nút +/- khóa đúng lúc để người dùng hiểu giới hạn, thay vì bấm mà không có gì xảy ra
            $$("[data-bk-step]").forEach(function (btn) {
                var isAdult = btn.dataset["for"] === "adults";
                var dir = +btn.dataset.bkStep;
                var off = false;
                if (dir < 0) {
                    off = isAdult ? a <= 1 : c <= 0;
                } else {
                    off = a + c >= limit;
                }
                btn.disabled = off;
            });

            var note = $("#bkGuestNote");
            if (note) {
                note.textContent =
                    a +
                    c +
                    " khách · tối đa " +
                    limit +
                    " khách cho một đơn" +
                    (a + c >= limit ? " (đã đạt giới hạn số chỗ còn lại)" : "");
            }
        }

        $$("[data-bk-step]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var isAdult = btn.dataset["for"] === "adults";
                var dir = +btn.dataset.bkStep;
                var a = +inAdults.value,
                    c = +inChildren.value;
                if (isAdult) {
                    a += dir;
                } else {
                    c += dir;
                }
                if (a < 1 || c < 0 || a + c > limit) {
                    return;
                }
                inAdults.value = a;
                inChildren.value = c;
                // Đổi số khách khi đang dùng mã: hỏi lại server để số tiền giảm luôn đúng
                if (state.couponApplied) {
                    scheduleRequote();
                }
                render();
            });
        });

        /* ---------- mã giảm giá ---------- */
        function setCouponMsg(text, ok) {
            couponMsg.textContent = text || "";
            couponMsg.className =
                "bk-coupon__msg" + (text ? (ok ? " is-ok" : " is-bad") : "");
        }

        function clearCoupon(silent) {
            state.couponApplied = false;
            state.discount = 0;
            couponIn.readOnly = false;
            couponIn.value = silent ? couponIn.value : "";
            couponRemove.hidden = true;
            couponBtn.hidden = false;
            if (!silent) {
                setCouponMsg("");
            }
            render();
        }

        function quote(cb) {
            var code = couponIn.value.trim();
            if (!code) {
                setCouponMsg("Vui lòng nhập mã giảm giá.", false);
                couponIn.focus();
                cb && cb(false);
                return;
            }
            if (state.quoting) {
                return;
            }
            state.quoting = true;
            couponBtn.disabled = true;
            couponBtn.textContent = "Đang kiểm tra…";

            var fd = new FormData();
            fd.append("_token", form.elements._token.value);
            fd.append("code", code);
            fd.append("tourId", tourId);
            fd.append("numAdults", inAdults.value);
            fd.append("numChildren", inChildren.value);

            fetch(couponUrl, {
                method: "POST",
                body: fd,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
                credentials: "same-origin",
            })
                .then(function (r) {
                    return r.json();
                })
                .then(function (res) {
                    if (res && res.success) {
                        state.couponApplied = true;
                        state.discount = +res.discountAmount || 0;
                        couponIn.readOnly = true;
                        couponBtn.hidden = true;
                        couponRemove.hidden = false;
                        setCouponMsg(
                            res.message || "Đã áp dụng mã giảm giá.",
                            true,
                        );
                        cb && cb(true);
                    } else {
                        state.couponApplied = false;
                        state.discount = 0;
                        couponIn.readOnly = false;
                        couponRemove.hidden = true;
                        couponBtn.hidden = false;
                        setCouponMsg(
                            (res && res.message) || "Mã không hợp lệ.",
                            false,
                        );
                        cb && cb(false);
                    }
                })
                .catch(function () {
                    setCouponMsg(
                        "Không kiểm tra được mã lúc này. Kiểm tra mạng và thử lại.",
                        false,
                    );
                    cb && cb(false);
                })
                .then(function () {
                    state.quoting = false;
                    couponBtn.disabled = false;
                    couponBtn.textContent = "Áp dụng";
                    render();
                });
        }

        function scheduleRequote() {
            clearTimeout(state.quoteTimer);
            state.quoteTimer = setTimeout(function () {
                state.quoting = false;
                quote();
            }, 400);
        }

        couponBtn.addEventListener("click", function () {
            quote();
        });
        couponRemove.addEventListener("click", function () {
            clearCoupon(false);
            couponIn.focus();
        });
        couponIn.addEventListener("keydown", function (e) {
            // Enter trong ô mã chỉ áp mã, KHÔNG gửi cả biểu mẫu đặt tour
            if (e.key === "Enter") {
                e.preventDefault();
                if (!couponIn.readOnly) {
                    quote();
                }
            }
        });

        /* ---------- kiểm tra dữ liệu ---------- */
        var emailRe = /^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/;

        function normalizeTel(v) {
            var d = String(v || "").replace(/\D+/g, "");
            // +84901234567 / 84901234567 -> 0901234567
            if (/^84\d{9,10}$/.test(d)) {
                d = "0" + d.slice(2);
            }
            return d;
        }

        var rules = {
            fullName: function (v) {
                return v.trim().length < 2 ? "Vui lòng nhập họ và tên." : "";
            },
            tel: function (v) {
                return /^\d{10,11}$/.test(normalizeTel(v))
                    ? ""
                    : "Số điện thoại phải có 10-11 chữ số.";
            },
            email: function (v) {
                v = v.trim();
                if (!v) {
                    return "Vui lòng nhập email.";
                }
                if (v.length > 50) {
                    return "Email tối đa 50 ký tự.";
                }
                return emailRe.test(v)
                    ? ""
                    : "Email chưa đúng (ví dụ: ten@gmail.com).";
            },
            address: function (v) {
                return v.trim().length < 5
                    ? "Vui lòng nhập địa chỉ (số nhà, đường, tỉnh/thành)."
                    : "";
            },
        };

        function setError(input, msg) {
            var box = $("#bkErr-" + input.name);
            var wrap = input.closest(".bk-field");
            if (box) {
                box.textContent = msg;
            }
            if (wrap) {
                wrap.classList.toggle("has-error", !!msg);
            }
            if (msg) {
                input.setAttribute("aria-invalid", "true");
            } else {
                input.removeAttribute("aria-invalid");
            }
        }

        function checkField(input) {
            if (input.name === "tel") {
                input.value = normalizeTel(input.value);
            }
            var msg = rules[input.name](input.value);
            setError(input, msg);
            return !msg;
        }

        var fields = Object.keys(rules).map(function (n) {
            return form.elements[n];
        });
        fields.forEach(function (input) {
            input.addEventListener("blur", function () {
                if (input.value !== "" || input.hasAttribute("aria-invalid")) {
                    checkField(input);
                }
            });
            input.addEventListener("input", function () {
                if (input.hasAttribute("aria-invalid")) {
                    checkField(input);
                }
            });
        });

        var agree = $("#bkAgree");
        agree.addEventListener("change", function () {
            if (agree.checked) {
                $("#bkErr-agree").textContent = "";
            }
        });

        function validateAll() {
            var firstBad = null;
            fields.forEach(function (input) {
                if (!checkField(input) && !firstBad) {
                    firstBad = input;
                }
            });
            if (!agree.checked) {
                $("#bkErr-agree").textContent =
                    "Vui lòng đồng ý với điều kiện đặt tour để tiếp tục.";
                if (!firstBad) {
                    firstBad = agree;
                }
            }
            return firstBad;
        }

        /* ---------- gửi biểu mẫu ---------- */
        var submitting = false;
        var passedCouponCheck = false;

        function setBusy(on) {
            submitting = on;
            submitBtns.forEach(function (b) {
                if (on) {
                    b.dataset.label = b.textContent;
                    b.textContent = "Đang xử lý…";
                    b.setAttribute("aria-busy", "true");
                    b.disabled = true;
                } else {
                    if (b.dataset.label) {
                        b.textContent = b.dataset.label;
                    }
                    b.removeAttribute("aria-busy");
                    b.disabled = false;
                }
            });
        }

        form.addEventListener("submit", function (e) {
            if (submitting) {
                e.preventDefault();
                return;
            } // chống bấm đúp

            var bad = validateAll();
            if (bad) {
                e.preventDefault();
                bad.scrollIntoView({ behavior: "smooth", block: "center" });
                bad.focus({ preventScroll: true });
                toast(
                    "error",
                    "Vui lòng kiểm tra lại các mục được đánh dấu đỏ.",
                );
                return;
            }

            // Có nhập mã nhưng chưa bấm "Áp dụng": áp mã trước để người dùng thấy giá thật rồi mới đặt
            if (
                couponIn.value.trim() &&
                !state.couponApplied &&
                !passedCouponCheck
            ) {
                e.preventDefault();
                quote(function (ok) {
                    if (ok) {
                        toast(
                            "success",
                            "Đã áp mã giảm giá. Xem lại tổng tiền rồi bấm xác nhận.",
                        );
                    } else {
                        couponIn.focus();
                    }
                });
                return;
            }

            setBusy(true);
        });

        // Quay lại bằng nút Back của trình duyệt: mở khóa nút để người dùng thao tác tiếp
        window.addEventListener("pageshow", function (e) {
            if (e.persisted) {
                setBusy(false);
            }
        });

        // Lỗi từ server: chuyển tiêu điểm tới khối thông báo
        var serverErr = $("#bkServerErrors");
        if (serverErr) {
            serverErr.scrollIntoView({ block: "center" });
            serverErr.focus({ preventScroll: true });
        }

        // Đặt lại mã nếu server trả về kèm mã cũ
        if (couponIn.value.trim()) {
            setCouponMsg("Bấm “Áp dụng” để kiểm tra lại mã này.", false);
        }
        render();
    }

    /* =====================================================================
       2. CHI TIẾT ĐƠN (tour-booked): đếm ngược, hộp thoại hủy, sao chép mã
       ===================================================================== */
    function initBooked() {
        // Đếm ngược thời hạn giữ chỗ
        var cd = $(".bk-countdown[data-deadline]");
        if (cd) {
            var end = new Date(cd.dataset.deadline).getTime();
            var tick = function () {
                var ms = end - Date.now();
                if (isNaN(end)) {
                    return false;
                }
                if (ms <= 0) {
                    cd.textContent = "đã hết hạn";
                    return false;
                }
                var s = Math.floor(ms / 1000),
                    h = Math.floor(s / 3600),
                    m = Math.floor((s % 3600) / 60),
                    sec = s % 60;
                cd.textContent =
                    h > 0
                        ? h + " giờ " + m + " phút"
                        : m + " phút " + (sec < 10 ? "0" : "") + sec + " giây";
                return true;
            };
            if (tick()) {
                var timer = setInterval(function () {
                    if (!tick()) {
                        clearInterval(timer);
                    }
                }, 1000);
            }
        }

        // Hộp thoại xác nhận hủy
        var dlg = $("#bkCancelDialog");
        var open = $("[data-open-cancel]");
        if (dlg && open) {
            open.addEventListener("click", function () {
                if (typeof dlg.showModal === "function") {
                    dlg.showModal();
                } else if (
                    window.confirm(
                        "Hủy đơn này? Chỗ sẽ được trả lại và không thể khôi phục.",
                    )
                ) {
                    dlg.querySelector("form").submit(); // trình duyệt cũ không có <dialog>
                }
            });
            $$("[data-close-cancel]", dlg).forEach(function (b) {
                b.addEventListener("click", function () {
                    dlg.close();
                });
            });
            dlg.addEventListener("click", function (e) {
                if (e.target === dlg) {
                    dlg.close();
                }
            }); // bấm nền để đóng
            dlg.querySelector("form").addEventListener("submit", function () {
                var b = dlg.querySelector("button[type=submit]");
                b.disabled = true;
                b.textContent = "Đang hủy…";
            });
        }

        // Sao chép mã đơn
        $$("[data-copy]").forEach(function (btn) {
            btn.addEventListener("click", function () {
                var src = $(btn.dataset.copy);
                if (!src) {
                    return;
                }
                var text = src.textContent.trim();
                var done = function () {
                    toast("success", "Đã sao chép mã đơn " + text);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () {
                        window.prompt("Sao chép mã đơn:", text);
                    });
                } else {
                    window.prompt("Sao chép mã đơn:", text);
                }
            });
        });
    }

    /* =====================================================================
       3. ĐÁNH GIÁ SAO dùng được bằng bàn phím (không đổi logic cũ trong custom-js.js)
       ===================================================================== */
    function initStars() {
        var group = $("#rating-stars");
        if (!group) {
            return;
        }
        var stars = $$("i[data-value]", group);
        var jq = window.jQuery;

        stars.forEach(function (star, idx) {
            var n = +star.dataset.value;
            star.setAttribute("role", "radio");
            star.setAttribute("aria-label", n + " sao");
            star.setAttribute("aria-checked", "false");
            star.setAttribute("tabindex", idx === 0 ? "0" : "-1");

            star.addEventListener("keydown", function (e) {
                var to = null;
                if (e.key === "ArrowRight" || e.key === "ArrowUp") {
                    to = Math.min(stars.length - 1, idx + 1);
                } else if (e.key === "ArrowLeft" || e.key === "ArrowDown") {
                    to = Math.max(0, idx - 1);
                } else if (e.key === "Enter" || e.key === " ") {
                    to = idx;
                }
                if (to === null) {
                    return;
                }
                e.preventDefault();
                stars[to].focus();
                if (jq) {
                    jq(stars[to]).trigger("mouseover");
                } // tô sao như khi rê chuột
                stars[to].click(); // gán điểm như khi bấm chuột
            });

            star.addEventListener("click", function () {
                stars.forEach(function (s, i) {
                    s.setAttribute(
                        "aria-checked",
                        i === idx ? "true" : "false",
                    );
                    s.setAttribute("tabindex", i === idx ? "0" : "-1");
                });
            });
        });
    }

    /* =====================================================================
       4. YÊU THÍCH (trang tours): nói rõ là lưu trên thiết bị này
       ===================================================================== */
    function initFavHint() {
        if (!$(".wt-heart")) {
            return;
        }
        document.addEventListener("click", function (e) {
            var h = e.target.closest && e.target.closest(".wt-heart");
            if (!h) {
                return;
            }
            // Chờ một nhịp: listener của trang (tv-tours-10.js) có thể chạy SAU listener này,
            // nên chỉ sau setTimeout 0 class is-on mới phản ánh đúng trạng thái mới
            setTimeout(function () {
                if (!h.classList.contains("is-on")) {
                    return;
                }
                try {
                    if (sessionStorage.getItem("uxFavHint")) {
                        return;
                    }
                    sessionStorage.setItem("uxFavHint", "1");
                } catch (err) {
                    /* chế độ riêng tư: vẫn hiện thông báo */
                }
                toast(
                    "info",
                    "Đã lưu tour. Bạn xem lại ở mục “Tour đã lưu” phía trên danh sách (chỉ trên thiết bị này).",
                );
            }, 0);
        });
    }

    /* =====================================================================
       5. LIÊN HỆ (#ctForm): kiểm tra từng ô, đếm ký tự, lưu nháp, chống gửi đúp
       ===================================================================== */
    function initContact() {
        var form = $("#ctForm");
        var done = $("#ctDone");
        var DRAFT = "travel:contact:draft";

        // Gửi xong: dọn bản nháp, chuyển tiêu điểm tới thông báo thành công
        if (done) {
            try {
                sessionStorage.removeItem(DRAFT);
            } catch (e) {
                /* bỏ qua */
            }
            done.focus();
            return;
        }
        if (!form) {
            return;
        }

        var msg = form.elements.message,
            count = $("#ctCount");
        var codeWrap = $("#ctCodeWrap"),
            codeIn = form.elements.booking_code;
        var submitBtn = $("#ctSubmit");

        /* ô "Mã đơn" chỉ hiện khi cần */
        function syncTopic() {
            var topic = (form.querySelector("input[name=topic]:checked") || {})
                .value;
            codeWrap.hidden = !(topic === "booking" || codeIn.value.trim());
        }
        $$("input[name=topic]", form).forEach(function (r) {
            r.addEventListener("change", syncTopic);
        });
        syncTopic();

        /* đếm ký tự */
        function updateCount() {
            var n = msg.value.length;
            count.textContent = n + "/2000";
            count.classList.toggle("is-near", n >= 1800);
        }
        msg.addEventListener("input", function () {
            updateCount();
            try {
                sessionStorage.setItem(DRAFT, msg.value);
            } catch (e) {
                /* bỏ qua */
            }
        });
        // khôi phục bản nháp nếu người dùng lỡ tải lại trang
        try {
            var saved = sessionStorage.getItem(DRAFT);
            if (saved && !msg.value) {
                msg.value = saved;
            }
        } catch (e) {
            /* bỏ qua */
        }
        updateCount();

        /* Ctrl/Cmd + Enter để gửi */
        msg.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                if (form.requestSubmit) {
                    form.requestSubmit();
                } else {
                    submitBtn.click();
                }
            }
        });

        /* kiểm tra dữ liệu: cùng luật với ContactController */
        var phoneRe = /^[0-9+\s.\-]{8,15}$/,
            emailRe = /^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/,
            codeRe = /^[A-Za-z0-9\-_#]+$/;
        var rules = {
            name: function (v) {
                return v.trim() ? "" : "Vui lòng nhập họ tên.";
            },
            phone_number: function (v) {
                return phoneRe.test(v.trim())
                    ? ""
                    : "Số điện thoại chưa đúng (8–15 ký tự: số, +, dấu cách, dấu chấm hoặc gạch ngang).";
            },
            email: function (v) {
                return emailRe.test(v.trim())
                    ? ""
                    : "Email chưa đúng (ví dụ: ten@gmail.com).";
            },
            message: function (v) {
                v = v.trim();
                if (!v) {
                    return "Vui lòng nhập nội dung.";
                }
                return v.length < 5
                    ? "Nội dung hơi ngắn, bạn mô tả thêm một chút nhé."
                    : "";
            },
            booking_code: function (v) {
                return !v.trim() || codeRe.test(v.trim())
                    ? ""
                    : "Mã đơn chỉ gồm chữ, số và dấu gạch.";
            },
        };

        function setErr(input, text) {
            var box = $("#ctErr-" + input.name),
                wrap = input.closest(".ct-field");
            if (box) {
                box.textContent = text;
            }
            if (wrap) {
                wrap.classList.toggle("has-error", !!text);
            }
            if (text) {
                input.setAttribute("aria-invalid", "true");
            } else {
                input.removeAttribute("aria-invalid");
            }
        }
        function check(input) {
            var t = rules[input.name](input.value);
            setErr(input, t);
            return !t;
        }
        Object.keys(rules).forEach(function (n) {
            var input = form.elements[n];
            input.addEventListener("blur", function () {
                if (input.value !== "" || input.hasAttribute("aria-invalid")) {
                    check(input);
                }
            });
            input.addEventListener("input", function () {
                if (input.hasAttribute("aria-invalid")) {
                    check(input);
                }
            });
        });

        var busy = false;
        form.addEventListener("submit", function (e) {
            if (busy) {
                e.preventDefault();
                return;
            }
            var firstBad = null;
            Object.keys(rules).forEach(function (n) {
                if (n === "booking_code" && codeWrap.hidden) {
                    return;
                }
                if (!check(form.elements[n]) && !firstBad) {
                    firstBad = form.elements[n];
                }
            });
            if (firstBad) {
                e.preventDefault();
                firstBad.scrollIntoView({
                    behavior: "smooth",
                    block: "center",
                });
                firstBad.focus({ preventScroll: true });
                return;
            }
            busy = true;
            submitBtn.disabled = true;
            submitBtn.setAttribute("aria-busy", "true");
            $("span", submitBtn).textContent = "Đang gửi…";
        });
        window.addEventListener("pageshow", function (e) {
            if (e.persisted) {
                busy = false;
                submitBtn.disabled = false;
                submitBtn.removeAttribute("aria-busy");
                $("span", submitBtn).textContent = "Gửi tin nhắn";
            }
        });

        var serverErr = $("#ctServerErrors");
        if (serverErr) {
            serverErr.focus({ preventScroll: true });
        }
    }

    /* =====================================================================
       6. HỒ SƠ (#pfForm, #pfPassForm, ảnh đại diện, gửi lại thư xác minh)
       ===================================================================== */
    function postForm(url, fd, headers) {
        var h = {
            "X-Requested-With": "XMLHttpRequest",
            Accept: "application/json",
        };
        Object.keys(headers || {}).forEach(function (k) {
            h[k] = headers[k];
        });
        return fetch(url, {
            method: "POST",
            body: fd,
            headers: h,
            credentials: "same-origin",
        }).then(function (r) {
            return r
                .json()
                .catch(function () {
                    return {};
                })
                .then(function (j) {
                    return { status: r.status, ok: r.ok, json: j };
                });
        });
    }
    function netMessage(res) {
        if (res.status === 419) {
            return "Phiên làm việc đã hết hạn. Vui lòng tải lại trang rồi thử lại.";
        }
        if (res.status === 429) {
            return "Bạn thao tác hơi nhanh, vui lòng thử lại sau ít phút.";
        }
        if (res.status === 401) {
            return "Bạn cần đăng nhập lại.";
        }
        return (
            (res.json && res.json.message) ||
            "Có lỗi xảy ra. Vui lòng thử lại sau."
        );
    }
    function fieldErr(prefix, input, text) {
        var box = $("#" + prefix + "-" + input.name);
        if (box) {
            box.textContent = text || "";
        }
        if (text) {
            input.setAttribute("aria-invalid", "true");
        } else {
            input.removeAttribute("aria-invalid");
        }
    }

    function initProfile() {
        var form = $("#pfForm");
        if (!form) {
            return;
        }

        var emailRe = /^[^\s@]+@[^\s@]+\.[A-Za-z]{2,}$/,
            phoneRe = /^[0-9+\-\s().]{8,15}$/;
        var saveBtn = $("#pfSave"),
            state = $("#pfState");
        var names = ["fullName", "phone", "email", "address"];
        function snapshot() {
            return names
                .map(function (n) {
                    return form.elements[n].value.trim();
                })
                .join("\u0001");
        }
        var base = snapshot();

        /* mức hoàn thiện hồ sơ cập nhật ngay khi lưu */
        function updateMeter() {
            var filled = names.filter(function (n) {
                return form.elements[n].value.trim() !== "";
            }).length;
            var pct = Math.round((filled / names.length) * 100);
            var meter = $(".pf-meter");
            if (meter) {
                meter.setAttribute("aria-valuenow", pct);
                $("i", meter).style.width = pct + "%";
            }
            $("#pfPercent").textContent = pct + "%";
            var miss = [];
            if (!form.elements.phone.value.trim()) {
                miss.push("số điện thoại");
            }
            if (!form.elements.address.value.trim()) {
                miss.push("địa chỉ");
            }
            $("#pfMissing").textContent = miss.length
                ? "Thêm " + miss.join(" và ") + " để đặt tour nhanh hơn."
                : "Hồ sơ của bạn đã đầy đủ.";
        }

        function isDirty() {
            return snapshot() !== base;
        }
        function syncDirty() {
            var d = isDirty();
            saveBtn.disabled = !d;
            state.textContent = d
                ? "Có thay đổi chưa lưu"
                : state.dataset.saved || "Chưa có thay đổi";
            state.classList.toggle("is-dirty", d);
        }
        names.forEach(function (n) {
            var input = form.elements[n];
            input.addEventListener("input", function () {
                fieldErr("pfErr", input, "");
                syncDirty();
            });
        });
        window.addEventListener("beforeunload", function (e) {
            if (isDirty()) {
                e.preventDefault();
                e.returnValue = "";
            }
        });

        function validate() {
            var bad = null;
            function fail(n, t) {
                fieldErr("pfErr", form.elements[n], t);
                if (!bad) {
                    bad = form.elements[n];
                }
            }
            names.forEach(function (n) {
                fieldErr("pfErr", form.elements[n], "");
            });
            if (!form.elements.fullName.value.trim()) {
                fail("fullName", "Vui lòng nhập họ tên.");
            } else if (form.elements.fullName.value.trim().length > 50) {
                fail("fullName", "Họ tên tối đa 50 ký tự.");
            }
            if (!emailRe.test(form.elements.email.value.trim())) {
                fail("email", "Email chưa đúng (ví dụ: ten@gmail.com).");
            }
            var ph = form.elements.phone.value.trim();
            if (ph && !phoneRe.test(ph)) {
                fail("phone", "Số điện thoại chưa đúng (8–15 ký tự).");
            }
            return bad;
        }

        var saving = false;
        form.addEventListener("submit", function (e) {
            e.preventDefault();
            if (saving || !isDirty()) {
                return;
            }
            var bad = validate();
            if (bad) {
                bad.scrollIntoView({ behavior: "smooth", block: "center" });
                bad.focus({ preventScroll: true });
                return;
            }

            saving = true;
            saveBtn.disabled = true;
            saveBtn.setAttribute("aria-busy", "true");
            saveBtn.textContent = "Đang lưu…";
            postForm(form.action, new FormData(form))
                .then(function (res) {
                    var j = res.json || {};
                    if (res.ok && j.success) {
                        base = snapshot();
                        var t = new Date();
                        state.dataset.saved =
                            "Đã lưu lúc " +
                            ("0" + t.getHours()).slice(-2) +
                            ":" +
                            ("0" + t.getMinutes()).slice(-2);
                        $("#pfName").textContent =
                            form.elements.fullName.value.trim();
                        updateMeter();
                        if (j.emailVerificationRequired) {
                            $("#pfPendingMail").textContent =
                                form.elements.email.value.trim();
                            $("#pfPending").hidden = false;
                            $("#pfPending").scrollIntoView({
                                behavior: "smooth",
                                block: "center",
                            });
                        }
                        toast("success", j.message || "Đã lưu thông tin.");
                    } else {
                        var m = netMessage(res),
                            target = null;
                        if (/email/i.test(m)) {
                            target = form.elements.email;
                        } else if (/họ tên/i.test(m)) {
                            target = form.elements.fullName;
                        } else if (/điện thoại/i.test(m)) {
                            target = form.elements.phone;
                        }
                        if (target) {
                            fieldErr("pfErr", target, m);
                            target.focus();
                        }
                        toast("error", m);
                    }
                })
                .catch(function () {
                    toast(
                        "error",
                        "Không kết nối được máy chủ. Kiểm tra mạng rồi thử lại.",
                    );
                })
                .then(function () {
                    saving = false;
                    saveBtn.removeAttribute("aria-busy");
                    saveBtn.textContent = "Lưu thông tin";
                    syncDirty();
                });
        });
        syncDirty();

        /* ----- đổi mật khẩu ----- */
        var pf = $("#pfPassForm"),
            pBtn = $("#pfPassSave"),
            pState = $("#pfPassState");
        $$("[data-pass-toggle]").forEach(function (b) {
            b.addEventListener("click", function () {
                var input = $(b.dataset.passToggle),
                    show = input.type === "password";
                input.type = show ? "text" : "password";
                b.setAttribute("aria-pressed", show ? "true" : "false");
                b.setAttribute(
                    "aria-label",
                    show ? "Ẩn mật khẩu" : "Hiện mật khẩu",
                );
                $("i", b).className = show ? "fal fa-eye-slash" : "fal fa-eye";
            });
        });

        function strength(v) {
            var s = 0;
            if (v.length >= 8) {
                s++;
            }
            if (/[a-z]/.test(v) && /[A-Z]/.test(v)) {
                s++;
            }
            if (/\d/.test(v)) {
                s++;
            }
            if (/[^A-Za-z0-9]/.test(v) || v.length >= 12) {
                s++;
            }
            return v ? Math.max(1, s) : 0;
        }
        pf.elements.newPass.addEventListener("input", function () {
            $("#pfStrength").dataset.level = strength(
                pf.elements.newPass.value,
            );
            fieldErr("pfErr", pf.elements.newPass, "");
        });
        ["oldPass", "confirmPass"].forEach(function (n) {
            pf.elements[n].addEventListener("input", function () {
                fieldErr("pfErr", pf.elements[n], "");
            });
        });

        var changing = false;
        pf.addEventListener("submit", function (e) {
            e.preventDefault();
            if (changing) {
                return;
            }
            var o = pf.elements.oldPass,
                n = pf.elements.newPass,
                c = pf.elements.confirmPass,
                bad = null;
            function fail(input, t) {
                fieldErr("pfErr", input, t);
                if (!bad) {
                    bad = input;
                }
            }
            ["oldPass", "newPass", "confirmPass"].forEach(function (k) {
                fieldErr("pfErr", pf.elements[k], "");
            });
            if (!o.value) {
                fail(o, "Vui lòng nhập mật khẩu hiện tại.");
            }
            if (n.value.length < 8) {
                fail(n, "Mật khẩu mới phải có ít nhất 8 ký tự.");
            } else if (n.value.length > 72) {
                fail(n, "Mật khẩu mới tối đa 72 ký tự.");
            } else if (n.value === o.value) {
                fail(n, "Mật khẩu mới trùng với mật khẩu hiện tại.");
            }
            if (!bad && c.value !== n.value) {
                fail(c, "Hai mật khẩu chưa khớp.");
            }
            if (bad) {
                bad.focus();
                return;
            }

            changing = true;
            pBtn.disabled = true;
            pBtn.textContent = "Đang cập nhật…";
            var fd = new FormData();
            fd.append("_token", pf.elements._token.value);
            fd.append("oldPass", o.value);
            fd.append("newPass", n.value);
            postForm(pf.action, fd)
                .then(function (res) {
                    var j = res.json || {};
                    if (res.ok && j.success) {
                        pf.reset();
                        $("#pfStrength").dataset.level = 0;
                        pState.textContent = "Đã đổi mật khẩu";
                        toast(
                            "success",
                            j.message || "Đổi mật khẩu thành công!",
                        );
                    } else {
                        var m = netMessage(res);
                        // lỗi 422 trả về thông điệp: gắn đúng vào ô gây lỗi
                        if (
                            /cũ|hiện tại/i.test(m) &&
                            /không chính xác/i.test(m)
                        ) {
                            fieldErr("pfErr", o, m);
                            o.focus();
                        } else if (/trùng/i.test(m)) {
                            fieldErr("pfErr", n, m);
                            n.focus();
                        } else if (/mới/i.test(m)) {
                            fieldErr("pfErr", n, m);
                            n.focus();
                        }
                        toast("error", m);
                    }
                })
                .catch(function () {
                    toast(
                        "error",
                        "Không kết nối được máy chủ. Kiểm tra mạng rồi thử lại.",
                    );
                })
                .then(function () {
                    changing = false;
                    pBtn.disabled = false;
                    pBtn.textContent = "Cập nhật mật khẩu";
                });
        });

        /* ----- ảnh đại diện: kiểm tra trước khi tải, hoàn tác nếu lỗi ----- */
        var fileIn = $("#pfAvatar"),
            img = $("#pfAvatarImg"),
            aErr = $("#pfAvatarErr"),
            aBusy = $("#pfAvatarBusy");
        fileIn.addEventListener("change", function () {
            var f = fileIn.files && fileIn.files[0];
            aErr.textContent = "";
            if (!f) {
                return;
            }
            if (
                ["image/jpeg", "image/png", "image/webp"].indexOf(f.type) === -1
            ) {
                aErr.textContent = "Chỉ nhận ảnh JPG, PNG hoặc WebP.";
                fileIn.value = "";
                return;
            }
            if (f.size > 5 * 1024 * 1024) {
                aErr.textContent =
                    "Ảnh lớn hơn 5 MB. Vui lòng chọn ảnh nhỏ hơn.";
                fileIn.value = "";
                return;
            }
            var previous = img.src,
                preview = URL.createObjectURL(f);
            img.src = preview;
            aBusy.hidden = false;
            var fd = new FormData();
            fd.append("avatar", f);
            postForm(fileIn.dataset.url, fd, {
                "X-CSRF-TOKEN": $("#pfToken").value,
            })
                .then(function (res) {
                    var j = res.json || {};
                    if (res.ok && j.success) {
                        if (j.url) {
                            img.src = j.url;
                            // đổi luôn ảnh ở thanh đầu trang
                            var hdr = $(".tv-user__btn img");
                            if (hdr) {
                                hdr.src = j.url;
                            }
                        }
                        toast(
                            "success",
                            j.message || "Đã cập nhật ảnh đại diện.",
                        );
                    } else {
                        img.src = previous; // lỗi thì trả lại ảnh cũ, không để ảnh "xem trước" đánh lừa người dùng
                        aErr.textContent = netMessage(res);
                    }
                })
                .catch(function () {
                    img.src = previous;
                    aErr.textContent =
                        "Không tải được ảnh lên. Kiểm tra mạng rồi thử lại.";
                })
                .then(function () {
                    aBusy.hidden = true;
                    fileIn.value = "";
                    URL.revokeObjectURL(preview);
                });
        });

        /* ----- gửi lại thư xác minh email mới (có đếm ngược chống bấm dồn) ----- */
        var resend = $("#pfResend");
        if (resend) {
            var idle = resend.textContent,
                cool = 0,
                timer = null;
            function tickCool() {
                if (cool <= 0) {
                    clearInterval(timer);
                    resend.disabled = false;
                    resend.textContent = idle;
                    return;
                }
                resend.textContent = "Gửi lại sau " + cool + " giây";
                cool--;
            }
            resend.addEventListener("click", function () {
                var fd = new FormData();
                fd.append("_token", $("#pfToken").value);
                fd.append("email", $("#pfPendingMail").textContent.trim());
                resend.disabled = true;
                postForm(resend.dataset.url, fd)
                    .then(function (res) {
                        var j = res.json || {};
                        toast(
                            res.ok && j.success ? "success" : "error",
                            netMessage(res),
                        );
                    })
                    .catch(function () {
                        toast("error", "Không kết nối được máy chủ.");
                    })
                    .then(function () {
                        cool = 60;
                        tickCool();
                        timer = setInterval(tickCool, 1000);
                    });
            });
        }
    }

    /* =====================================================================
       7. CHI TIẾT BLOG: thanh tiến độ đọc, mục lục đang đọc, chia sẻ
       ===================================================================== */
    function initPost() {
        var article = $(".px-article");
        if (!article) {
            return;
        }

        var bar = $("#postProgress i");
        if (bar) {
            var ticking = false;
            var update = function () {
                var r = article.getBoundingClientRect();
                var total = r.height - window.innerHeight * 0.6;
                var done = Math.min(
                    1,
                    Math.max(
                        0,
                        (-r.top + window.innerHeight * 0.3) /
                            Math.max(1, total),
                    ),
                );
                bar.style.transform = "scaleX(" + done.toFixed(3) + ")";
                ticking = false;
            };
            window.addEventListener(
                "scroll",
                function () {
                    if (!ticking) {
                        ticking = true;
                        requestAnimationFrame(update);
                    }
                },
                { passive: true },
            );
            update();
        }

        var links = $$("[data-toc]");
        if (links.length && "IntersectionObserver" in window) {
            var map = {};
            links.forEach(function (a) {
                map[a.getAttribute("href").slice(1)] = a;
            });
            var io = new IntersectionObserver(
                function (entries) {
                    entries.forEach(function (en) {
                        if (en.isIntersecting && map[en.target.id]) {
                            links.forEach(function (a) {
                                a.classList.remove("is-on");
                                a.removeAttribute("aria-current");
                            });
                            map[en.target.id].classList.add("is-on");
                            map[en.target.id].setAttribute(
                                "aria-current",
                                "true",
                            );
                        }
                    });
                },
                { rootMargin: "-20% 0px -65% 0px" },
            );
            $$(".px-article__sec").forEach(function (s) {
                io.observe(s);
            });
        }

        var share = $("[data-share-copy]");
        if (share) {
            var label = $("span", share),
                original = label.textContent;
            share.addEventListener("click", function () {
                var url = location.href.split("#")[0],
                    title = share.dataset.shareTitle || document.title;
                var ok = function () {
                    label.textContent = "Đã sao chép";
                    toast("success", "Đã sao chép liên kết bài viết.");
                    setTimeout(function () {
                        label.textContent = original;
                    }, 2200);
                };
                if (
                    navigator.share &&
                    /Mobi|Android/i.test(navigator.userAgent)
                ) {
                    navigator
                        .share({ title: title, url: url })
                        .catch(function () {
                            /* người dùng huỷ */
                        });
                } else if (
                    navigator.clipboard &&
                    navigator.clipboard.writeText
                ) {
                    navigator.clipboard.writeText(url).then(ok, function () {
                        window.prompt("Sao chép liên kết:", url);
                    });
                } else {
                    window.prompt("Sao chép liên kết:", url);
                }
            });
        }
    }

    /* =====================================================================
       8. ĐĂNG NHẬP (#lgForm): tên đăng nhập hoặc email, Caps Lock, trạng thái đang xử lý
       ===================================================================== */
    function initLogin() {
        var form = $("#lgForm");
        if (!form) {
            return;
        }

        var user = $("#lgUser"),
            pass = $("#lgPass"),
            caps = $("#lgCaps");
        var errUser = $("#lgErrUser"),
            errPass = $("#lgErrPass"),
            errForm = $("#lgErrForm");
        var btn = $("#lgSubmit"),
            label = $("span", btn),
            busy = false;

        /* hiện/ẩn mật khẩu (cả trang đăng nhập) */
        $$("[data-ux-pass]").forEach(function (b) {
            b.addEventListener("click", function () {
                var input = $(b.dataset.uxPass),
                    show = input.type === "password";
                input.type = show ? "text" : "password";
                b.setAttribute("aria-pressed", show ? "true" : "false");
                b.setAttribute(
                    "aria-label",
                    show ? "Ẩn mật khẩu" : "Hiện mật khẩu",
                );
                $("i", b).className = show ? "fal fa-eye-slash" : "fal fa-eye";
            });
        });

        /* cảnh báo Caps Lock: lỗi gõ nhầm phổ biến nhất ở ô mật khẩu */
        function capsState(e) {
            if (e.getModifierState) {
                caps.hidden = !e.getModifierState("CapsLock");
            }
        }
        pass.addEventListener("keydown", capsState);
        pass.addEventListener("keyup", capsState);
        pass.addEventListener("blur", function () {
            caps.hidden = true;
        });

        function setErr(box, input, text) {
            box.textContent = text || "";
            box.style.display = text ? "block" : "none";
            if (input) {
                if (text) {
                    input.setAttribute("aria-invalid", "true");
                } else {
                    input.removeAttribute("aria-invalid");
                }
            }
        }
        function formErr(text) {
            errForm.textContent = text || "";
            errForm.hidden = !text;
        }
        user.addEventListener("input", function () {
            setErr(errUser, user, "");
            formErr("");
        });
        pass.addEventListener("input", function () {
            setErr(errPass, pass, "");
            formErr("");
        });

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            if (busy) {
                return;
            }
            formErr("");
            var u = user.value.trim(),
                p = pass.value,
                bad = null;
            setErr(errUser, user, "");
            setErr(errPass, pass, "");
            if (!u) {
                setErr(
                    errUser,
                    user,
                    "Vui lòng nhập tên đăng nhập hoặc email.",
                );
                bad = user;
            }
            if (!p) {
                setErr(errPass, pass, "Vui lòng nhập mật khẩu.");
                if (!bad) {
                    bad = pass;
                }
            }
            if (bad) {
                bad.focus();
                return;
            }

            busy = true;
            btn.disabled = true;
            btn.setAttribute("aria-busy", "true");
            label.textContent = "Đang đăng nhập…";

            // Mở khóa nút khi đăng nhập chưa thành công để người dùng thử lại
            function unlock() {
                busy = false;
                btn.disabled = false;
                btn.removeAttribute("aria-busy");
                label.textContent = "Đăng nhập";
            }

            var fd = new FormData();
            fd.append("_token", form.elements._token.value);
            fd.append("username", u);
            fd.append("password", p);

            postForm(form.getAttribute("action"), fd)
                .then(function (res) {
                    var j = res.json || {};
                    if (res.ok && j.success) {
                        label.textContent = "Đang chuyển trang…"; // giữ nút khóa trong lúc chuyển trang
                        window.location.href = j.redirectUrl || "/";
                        return;
                    }
                    unlock();
                    formErr(
                        res.ok
                            ? j.message || "Đăng nhập chưa thành công."
                            : netMessage(res),
                    );
                    pass.value = "";
                    pass.focus();
                })
                .catch(function () {
                    unlock();
                    formErr(
                        "Không kết nối được máy chủ. Kiểm tra mạng rồi thử lại.",
                    );
                });
        });
        window.addEventListener("pageshow", function (e) {
            if (e.persisted) {
                busy = false;
                btn.disabled = false;
                btn.removeAttribute("aria-busy");
                label.textContent = "Đăng nhập";
            }
        });
    }

    /* =====================================================================
       9. CHI TIẾT ĐƠN: "Thêm vào lịch" (.ics tạo ngay trên trình duyệt) + in
       ===================================================================== */
    function icsEscape(s) {
        return String(s || "")
            .replace(/\\/g, "\\\\")
            .replace(/;/g, "\\;")
            .replace(/,/g, "\\,")
            .replace(/\r?\n/g, "\\n");
    }
    function icsFold(line) {
        // RFC 5545: dòng tối đa 75 byte; ở đây gấp theo 66 ký tự để an toàn với tiếng Việt có dấu
        var out = [];
        while (line.length > 66) {
            out.push(line.slice(0, 66));
            line = " " + line.slice(66);
        }
        out.push(line);
        return out.join("\r\n");
    }
    function ymd(d) {
        return String(d).replace(/-/g, "");
    }
    function plusOneDay(iso) {
        var p = iso.split("-"),
            d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + 1));
        return (
            d.getUTCFullYear() +
            ("0" + (d.getUTCMonth() + 1)).slice(-2) +
            ("0" + d.getUTCDate()).slice(-2)
        );
    }
    function buildIcs(o) {
        var now = new Date()
            .toISOString()
            .replace(/[-:]/g, "")
            .replace(/\.\d+Z$/, "Z");
        var lines = [
            "BEGIN:VCALENDAR",
            "VERSION:2.0",
            "PRODID:-//Travela//Tour booking//VI",
            "CALSCALE:GREGORIAN",
            "BEGIN:VEVENT",
            "UID:" + icsEscape(o.code) + "@travela",
            "DTSTAMP:" + now,
            "DTSTART;VALUE=DATE:" + ymd(o.start),
            "DTEND;VALUE=DATE:" + plusOneDay(o.end), // sự kiện cả ngày: ngày kết thúc tính loại trừ
            "SUMMARY:" + icsEscape(o.title),
            "LOCATION:" + icsEscape(o.place),
            "DESCRIPTION:" + icsEscape("Mã đơn: " + o.code + "\n" + o.url),
            "URL:" + o.url,
            "END:VEVENT",
            "END:VCALENDAR",
        ];
        return lines.map(icsFold).join("\r\n") + "\r\n";
    }
    function initIcs() {
        var btn = $("[data-ics]");
        if (btn) {
            btn.addEventListener("click", function () {
                var d = btn.dataset;
                var blob = new Blob(
                    [
                        buildIcs({
                            title: d.title,
                            start: d.start,
                            end: d.end,
                            place: d.place,
                            code: d.code,
                            url: d.url,
                        }),
                    ],
                    { type: "text/calendar;charset=utf-8" },
                );
                var a = document.createElement("a");
                a.href = URL.createObjectURL(blob);
                a.download =
                    "tour-" +
                    String(d.code).replace(/[^A-Za-z0-9\-_]/g, "") +
                    ".ics";
                document.body.appendChild(a);
                a.click();
                setTimeout(function () {
                    URL.revokeObjectURL(a.href);
                    a.remove();
                }, 500);
                toast(
                    "success",
                    "Đã tải tệp lịch. Mở tệp để thêm chuyến đi vào lịch của bạn.",
                );
            });
        }
    }

    /* =====================================================================
       10. TRANG TOUR: kệ "đã lưu" + "xem gần đây" (dữ liệu nằm trong trình duyệt này)
       Khóa: wt_fav (danh sách id, do tv-tours-10.js quản lý), wt_fav_meta (ảnh chụp thông tin tour), wt_recent.
       ===================================================================== */
    var FAV = "wt_fav",
        META = "wt_fav_meta",
        RECENT = "wt_recent";
    function lsGet(k, fb) {
        try {
            return JSON.parse(localStorage.getItem(k)) || fb;
        } catch (e) {
            return fb;
        }
    }
    function lsSet(k, v) {
        try {
            localStorage.setItem(k, JSON.stringify(v));
        } catch (e) {
            /* chế độ riêng tư */
        }
    }
    function sameOrigin(u) {
        try {
            return new URL(u, location.href).origin === location.origin;
        } catch (e) {
            return false;
        }
    }
    function priceNum(t) {
        return parseInt(String(t || "").replace(/\D+/g, ""), 10) || 0;
    }
    function snapshotFromCard(id) {
        var heart = document.querySelector('.wt-heart[data-fav="' + id + '"]');
        var card = heart && heart.closest(".wt-card");
        if (!card) {
            return null;
        }
        var a = $(".wt-card__title a", card),
            img = $(".wt-card__img img", card),
            pl = $(".wt-card__place", card),
            pr = $(".wt-card__price b", card);
        if (!a) {
            return null;
        }
        return {
            id: String(id),
            title: a.textContent.trim(),
            url: a.getAttribute("href"),
            img: img ? img.getAttribute("src") : "",
            dest: pl ? pl.textContent.trim() : "",
            price: priceNum(pr && pr.textContent),
        };
    }
    function detailMeta() {
        var el = $("#uxTourMeta");
        if (!el) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return null;
        }
    }
    function miniCard(t, removable) {
        var a = document.createElement("a");
        a.className = "ux-mini";
        a.href = sameOrigin(t.url) ? t.url : "#";
        if (t.img && /^(https?:)?\/\/|^\//.test(t.img)) {
            var im = document.createElement("img");
            im.src = t.img;
            im.alt = "";
            im.loading = "lazy";
            im.width = 64;
            im.height = 64;
            a.appendChild(im);
        }
        var box = document.createElement("span"),
            b = document.createElement("b"),
            s = document.createElement("small");
        b.textContent = t.title || "Tour";
        s.textContent =
            (t.dest ? t.dest + " · " : "") +
            (t.price ? "từ " + t.price.toLocaleString("vi-VN") + "đ" : "");
        box.appendChild(b);
        box.appendChild(s);
        a.appendChild(box);
        if (removable) {
            var rm = document.createElement("button");
            rm.type = "button";
            rm.className = "ux-mini__rm";
            rm.dataset.uxUnsave = t.id;
            rm.setAttribute("aria-label", "Bỏ lưu tour " + (t.title || ""));
            rm.innerHTML = '<i class="fal fa-times" aria-hidden="true"></i>';
            a.appendChild(rm);
        }
        return a;
    }
    function renderShelves() {
        var saved = $("#uxSaved"),
            recent = $("#uxRecent");
        if (!saved && !recent) {
            return;
        }
        if (saved) {
            var ids = lsGet(FAV, []),
                meta = lsGet(META, {}),
                row = $("#uxSavedRow");
            var items = ids
                .map(function (id) {
                    return meta[id];
                })
                .filter(Boolean);
            row.textContent = "";
            items.forEach(function (t) {
                row.appendChild(miniCard(t, true));
            });
            $("#uxSavedN").textContent = items.length
                ? "(" + items.length + ")"
                : "";
            saved.hidden = items.length === 0;
        }
        if (recent) {
            var list = lsGet(RECENT, []),
                rrow = $("#uxRecentRow");
            rrow.textContent = "";
            list.forEach(function (t) {
                rrow.appendChild(miniCard(t, false));
            });
            recent.hidden = list.length === 0;
        }
    }
    function initShelves() {
        // Trang chi tiết: ghi nhớ tour vừa xem (tối đa 6, không trùng)
        var m = detailMeta();
        if (m && m.id) {
            var list = lsGet(RECENT, []).filter(function (t) {
                return t.id !== m.id;
            });
            list.unshift(m);
            lsSet(RECENT, list.slice(0, 6));
        }

        // Bấm tim: chờ trang xử lý xong (thứ tự listener không cố định) rồi lưu/xóa ảnh chụp thông tin tour
        document.addEventListener("click", function (e) {
            var h = e.target.closest && e.target.closest("[data-fav]");
            if (!h) {
                return;
            }
            var id = String(h.dataset.fav);
            setTimeout(function () {
                var ids = lsGet(FAV, []),
                    meta = lsGet(META, {});
                if (ids.indexOf(id) !== -1) {
                    var snap =
                        snapshotFromCard(id) || (m && m.id === id ? m : null);
                    if (snap) {
                        meta[id] = snap;
                    }
                } else {
                    delete meta[id];
                }
                lsSet(META, meta);
                renderShelves();
            }, 0);
        });

        // Bỏ lưu / xóa kệ
        document.addEventListener("click", function (e) {
            var rm = e.target.closest && e.target.closest("[data-ux-unsave]");
            if (rm) {
                e.preventDefault();
                e.stopPropagation();
                var id = String(rm.dataset.uxUnsave);
                lsSet(
                    FAV,
                    lsGet(FAV, []).filter(function (x) {
                        return x !== id;
                    }),
                );
                var meta = lsGet(META, {});
                delete meta[id];
                lsSet(META, meta);
                document.dispatchEvent(new Event("wt:cards")); // để các nút tim trên trang tô lại trạng thái
                renderShelves();
                return;
            }
            var clr = e.target.closest && e.target.closest("[data-ux-clear]");
            if (clr) {
                if (clr.dataset.uxClear === "saved") {
                    lsSet(FAV, []);
                    lsSet(META, {});
                    document.dispatchEvent(new Event("wt:cards"));
                } else {
                    lsSet(RECENT, []);
                }
                renderShelves();
            }
        });
        renderShelves();
    }

    /* =====================================================================
       11. CHI TIẾT TOUR: In lịch trình; BLOG: mục lục gọn trên điện thoại; HỒ SƠ: thanh phần
       ===================================================================== */
    function initMisc() {
        var pr = $("[data-print]");
        if (pr) {
            pr.addEventListener("click", function () {
                window.print();
            });
        }
        // In: mở hết các ngày của lịch trình để bản in đầy đủ
        window.addEventListener("beforeprint", function () {
            $$("details.td-day, details.px-day").forEach(function (d) {
                d.open = true;
            });
        });

        var toc = $("#postToc");
        if (
            toc &&
            window.matchMedia &&
            window.matchMedia("(max-width: 991px)").matches
        ) {
            toc.removeAttribute("open");
        }

        var tabs = $$(".pf-tabs a[href^='#']");
        if (tabs.length && "IntersectionObserver" in window) {
            var io = new IntersectionObserver(
                function (entries) {
                    entries.forEach(function (en) {
                        if (en.isIntersecting) {
                            tabs.forEach(function (a) {
                                a.classList.toggle(
                                    "is-on",
                                    a.getAttribute("href") ===
                                        "#" + en.target.id,
                                );
                            });
                        }
                    });
                },
                { rootMargin: "-25% 0px -60% 0px" },
            );
            tabs.forEach(function (a) {
                var s = $(a.getAttribute("href"));
                if (s) {
                    io.observe(s);
                }
            });
        }
    }

    function ready(fn) {
        if (document.readyState !== "loading") {
            fn();
        } else {
            document.addEventListener("DOMContentLoaded", fn);
        }
    }
    ready(function () {
        // Chống khởi tạo hai lần (ví dụ file bị nạp trùng): mỗi nút sẽ không bị gắn sự kiện hai lần
        if (window.__uxPassInit) {
            return;
        }
        window.__uxPassInit = true;
        initBooking();
        initBooked();
        initStars();
        initFavHint();
        initContact();
        initProfile();
        initPost();
        initLogin();
        initIcs();
        initShelves();
        initMisc();
    });
})();
