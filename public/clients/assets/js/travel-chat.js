/* Travel Chat — khung chat liên hệ (JS thuần).
 *
 * Hai chế độ dùng CHUNG một bộ máy:
 *   - widget : bong bóng nổi ở góc trang (#tcWidget) + khung chat nhỏ, thu nhỏ/đóng được
 *   - page   : khung chat lớn kiểu Messenger trong trang /contact ([data-travel-chat="page"])
 *
 * Cách gửi tin đi nằm trong "adapter", nên sau này đổi sang tư vấn viên thật hoặc AI chỉ cần
 * đăng ký adapter mới, KHÔNG phải sửa giao diện:
 *
 *   TravelChat.registerAdapter('advisor', {
 *     statusText: 'Tư vấn viên đang trực tuyến',   // dòng trạng thái dưới tên
 *     needsContact: false,                          // true = hỏi họ tên/SĐT/email trước khi gửi
 *     // Gửi tin của khách. Trả về Promise<{ replies: ['câu trả lời 1', ...] }> (có thể rỗng)
 *     send: function (msg, ctx) { return fetch(...).then(...); },
 *     // (tuỳ chọn) nhận tin đẩy từ máy chủ: gọi ctx.receive('nội dung')
 *     start: function (ctx) { ... }
 *   });
 *   TravelChat.use('advisor');
 *
 * Adapter mặc định 'email': gửi nội dung về hộp thư của cửa hàng qua POST /contact (có sẵn).
 */
(function () {
    "use strict";

    var cfg = window.TV_CHAT || {};
    var STORE = "travel:chat:v1";
    var CONTACT = "travel:chat:contact";
    var reduce =
        window.matchMedia &&
        matchMedia("(prefers-reduced-motion: reduce)").matches;

    var $ = function (s, r) {
        return (r || document).querySelector(s);
    };
    var el = function (tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    };
    var safe = function (fn, fallback) {
        try {
            return fn();
        } catch (err) {
            return fallback;
        }
    };
    var now = function () {
        var d = new Date();
        return (
            ("0" + d.getHours()).slice(-2) +
            ":" +
            ("0" + d.getMinutes()).slice(-2)
        );
    };

    /* ================== ADAPTERS ================== */
    var adapters = {};
    var current = "email";

    adapters.email = {
        statusText:
            "Phản hồi qua email hoặc điện thoại" +
            (cfg.hours ? " · " + cfg.hours : ""),
        needsContact: true,
        send: function (msg) {
            var body = new FormData();
            body.append("_token", cfg.csrf || "");
            body.append("name", msg.contact.name);
            body.append("phone_number", msg.contact.phone);
            body.append("email", msg.contact.email);
            body.append("message", msg.text);
            return fetch(cfg.url, {
                method: "POST",
                body: body,
                credentials: "same-origin",
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
            }).then(function (r) {
                if (r.status === 429)
                    throw new Error(
                        "Bạn gửi hơi nhanh, vui lòng đợi một phút rồi thử lại.",
                    );
                return r
                    .json()
                    .catch(function () {
                        return {};
                    })
                    .then(function (d) {
                        if (!r.ok || d.success === false)
                            throw new Error(
                                d.message || "Không gửi được tin nhắn lúc này.",
                            );
                        return {
                            replies: [
                                "Đã gửi cho tư vấn viên. Chúng tôi sẽ phản hồi qua " +
                                    (msg.contact.email || "email") +
                                    " hoặc số " +
                                    msg.contact.phone +
                                    ".",
                            ],
                        };
                    });
            });
        },
    };

    /* ================== UI ================== */
    function Chat(root, mode) {
        var self = this;
        this.root = root;
        this.mode = mode;
        this.msgs = safe(function () {
            return JSON.parse(sessionStorage.getItem(STORE)) || [];
        }, []);
        this.contact = safe(function () {
            return JSON.parse(sessionStorage.getItem(CONTACT)) || null;
        }, null);
        this.pending = []; // tin khách đã gõ nhưng chưa gửi (đang chờ để lại thông tin)
        this.busy = false;
        this.build();
        if (!this.msgs.length) this.greet();
        else
            this.msgs.forEach(function (m) {
                self.draw(m, true);
            });
        this.scroll();
        var a = adapters[current];
        if (a && a.start) a.start(this.ctx());
    }

    Chat.prototype.ctx = function () {
        var self = this;
        return {
            receive: function (text) {
                self.say("bot", text);
            },
            config: cfg,
        };
    };

    Chat.prototype.build = function () {
        var self = this;
        var a = adapters[current];
        var r = this.root;
        r.innerHTML = "";
        r.classList.add("tc-chat");

        var head = el("header", "tc-head");
        head.appendChild(
            el("span", "tc-avatar", '<i class="fal fa-headset"></i>'),
        );
        var who = el("div", "tc-who");
        who.appendChild(
            el(
                "b",
                "",
                safe(function () {
                    return cfg.title;
                }, "") || "Hỗ trợ khách hàng",
            ),
        );
        this.statusEl = el("small", "", a.statusText);
        who.appendChild(this.statusEl);
        head.appendChild(who);
        if (this.mode === "widget") {
            var min = el(
                "button",
                "tc-iconbtn",
                '<i class="fal fa-minus"></i>',
            );
            min.type = "button";
            min.setAttribute("aria-label", "Thu nhỏ");
            min.dataset.tc = "min";
            var close = el(
                "button",
                "tc-iconbtn",
                '<i class="fal fa-times"></i>',
            );
            close.type = "button";
            close.setAttribute("aria-label", "Đóng");
            close.dataset.tc = "close";
            head.appendChild(min);
            head.appendChild(close);
        }
        r.appendChild(head);

        this.list = el("div", "tc-list");
        this.list.setAttribute("role", "log");
        this.list.setAttribute("aria-live", "polite");
        r.appendChild(this.list);

        this.chips = el("div", "tc-chips");
        r.appendChild(this.chips);

        var form = el("form", "tc-composer");
        this.input = el("textarea", "tc-input");
        this.input.rows = 1;
        this.input.maxLength = 2000;
        this.input.placeholder = "Nhập tin nhắn…";
        this.input.setAttribute("aria-label", "Nhập tin nhắn");
        var send = el(
            "button",
            "tc-send",
            '<i class="fas fa-paper-plane"></i>',
        );
        send.type = "submit";
        send.setAttribute("aria-label", "Gửi");
        form.appendChild(this.input);
        form.appendChild(send);
        r.appendChild(form);
        if (this.mode === "widget" && cfg.contactUrl) {
            var more = el(
                "a",
                "tc-more",
                '<i class="fal fa-external-link-alt" aria-hidden="true"></i> Cần gửi chi tiết hơn? Mở biểu mẫu liên hệ',
            );
            more.href = cfg.contactUrl;
            r.appendChild(more);
        }

        form.addEventListener("submit", function (e) {
            e.preventDefault();
            self.submit();
        });
        this.input.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                self.submit();
            }
        });
        this.input.addEventListener("input", function () {
            self.input.style.height = "auto";
            self.input.style.height =
                Math.min(self.input.scrollHeight, 110) + "px";
        });
        r.addEventListener("click", function (e) {
            var chip = e.target.closest("[data-chip]");
            if (chip) {
                self.userSays(chip.dataset.chip);
                return;
            }
            var act = e.target.closest("[data-tc]");
            if (act && self.onAction) self.onAction(act.dataset.tc);
        });
    };

    Chat.prototype.save = function () {
        var self = this;
        safe(function () {
            sessionStorage.setItem(STORE, JSON.stringify(self.msgs.slice(-40)));
        });
    };

    Chat.prototype.scroll = function () {
        var l = this.list;
        requestAnimationFrame(function () {
            l.scrollTop = l.scrollHeight;
        });
    };

    Chat.prototype.draw = function (m, silent) {
        var row = el("div", "tc-row tc-row--" + m.from);
        var bubble = el("div", "tc-bubble");
        bubble.textContent = m.text;
        row.appendChild(bubble);
        row.appendChild(el("time", "", m.time));
        this.list.appendChild(row);
        if (!silent) this.scroll();
        return row;
    };

    Chat.prototype.say = function (from, text) {
        var m = { from: from, text: text, time: now() };
        this.msgs.push(m);
        this.save();
        return this.draw(m);
    };

    Chat.prototype.setChips = function (items) {
        var c = this.chips;
        c.innerHTML = "";
        (items || []).forEach(function (t) {
            var b = el("button", "tc-chip");
            b.type = "button";
            b.dataset.chip = t;
            b.textContent = t;
            c.appendChild(b);
        });
    };

    Chat.prototype.greet = function () {
        var name = cfg.siteName || "chúng tôi";
        this.say(
            "bot",
            "Xin chào, đây là kênh hỗ trợ của " +
                name +
                ". Câu hỏi thường gặp được trả lời ngay; tin nhắn khác sẽ chuyển tới tư vấn viên và phản hồi qua email hoặc điện thoại. Bạn muốn hỏi điều gì ạ?",
        );
        this.setChips(cfg.chips || []);
    };

    Chat.prototype.typing = function (on) {
        if (on) {
            this.typingEl = el(
                "div",
                "tc-row tc-row--bot",
                '<div class="tc-bubble tc-typing"><i></i><i></i><i></i></div>',
            );
            this.list.appendChild(this.typingEl);
            this.scroll();
        } else if (this.typingEl) {
            this.typingEl.remove();
            this.typingEl = null;
        }
    };

    /* khách nhắn (gõ hoặc bấm gợi ý) */
    Chat.prototype.userSays = function (text) {
        text = (text || "").trim();
        if (!text || this.busy) return;
        var self = this;
        this.say("user", text);
        this.setChips([]);

        // câu hỏi thường gặp: trả lời ngay bằng thông tin thật của hệ thống
        var faq = cfg.faq || {};
        if (faq[text]) {
            this.typing(true);
            setTimeout(
                function () {
                    self.typing(false);
                    self.say("bot", faq[text]);
                    self.setChips(
                        (cfg.chips || []).filter(function (c) {
                            return c !== text;
                        }),
                    );
                },
                reduce ? 0 : 450,
            );
            return;
        }

        this.pending.push(text);
        var a = adapters[current];
        if (
            a.needsContact &&
            !(
                this.contact &&
                this.contact.name &&
                this.contact.phone &&
                this.contact.email
            )
        ) {
            this.askContact();
        } else {
            this.flush();
        }
    };

    Chat.prototype.submit = function () {
        var v = this.input.value;
        this.input.value = "";
        this.input.style.height = "auto";
        this.userSays(v);
    };

    /* thẻ để lại thông tin liên hệ ngay trong khung chat */
    Chat.prototype.askContact = function () {
        if (this.formEl) return;
        var self = this;
        var c = this.contact || {};
        var intro = this.say(
            "bot",
            "Để tư vấn viên phản hồi bạn, vui lòng để lại thông tin liên hệ nhé.",
        );
        var card = el("form", "tc-card");
        card.noValidate = true;
        card.innerHTML =
            '<label>Họ và tên<input name="name" autocomplete="name" maxlength="100"></label>' +
            '<label>Số điện thoại<input name="phone" autocomplete="tel" inputmode="tel" maxlength="15"></label>' +
            '<label>Email<input name="email" type="email" autocomplete="email" maxlength="255"></label>' +
            '<p class="tc-err" role="alert"></p>' +
            '<button type="submit" class="tc-btn">Gửi tin nhắn</button>';
        card.elements.name.value = c.name || "";
        card.elements.phone.value = c.phone || "";
        card.elements.email.value = c.email || "";
        this.list.appendChild(card);
        this.formEl = card;
        this.scroll();
        setTimeout(function () {
            (c.name ? card.elements.phone : card.elements.name).focus();
        }, 60);

        card.addEventListener("submit", function (e) {
            e.preventDefault();
            var name = card.elements.name.value.trim();
            var phone = card.elements.phone.value.trim();
            var email = card.elements.email.value.trim();
            var err = $(".tc-err", card);
            var fail = function (input, msg) {
                [
                    card.elements.name,
                    card.elements.phone,
                    card.elements.email,
                ].forEach(function (i) {
                    i.removeAttribute("aria-invalid");
                });
                input.setAttribute("aria-invalid", "true");
                input.setAttribute("aria-describedby", "tcErr");
                err.id = "tcErr";
                err.textContent = msg;
                input.focus();
            };
            if (!name) {
                fail(card.elements.name, "Vui lòng nhập họ và tên.");
                return;
            }
            if (!/^[0-9+\s.\-]{8,15}$/.test(phone)) {
                fail(card.elements.phone, "Số điện thoại chưa đúng (8–15 số).");
                return;
            }
            if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
                fail(card.elements.email, "Email chưa đúng định dạng.");
                return;
            }
            err.textContent = "";
            self.contact = { name: name, phone: phone, email: email };
            safe(function () {
                sessionStorage.setItem(CONTACT, JSON.stringify(self.contact));
            });
            card.remove();
            self.formEl = null;
            self.flush();
        });
    };

    /* gửi các tin đang chờ qua adapter */
    Chat.prototype.flush = function () {
        var self = this;
        if (!this.pending.length) return;
        var text = this.pending.join("\n");
        this.pending = [];
        this.busy = true;
        this.typing(true);
        var a = adapters[current];
        Promise.resolve(
            a.send({ text: text, contact: this.contact }, this.ctx()),
        )
            .then(function (res) {
                self.typing(false);
                ((res && res.replies) || []).forEach(function (t) {
                    self.say("bot", t);
                });
                self.busy = false;
                // gợi ý lại các câu hỏi có đáp án ngay để người dùng hỏi tiếp
                self.setChips(Object.keys(cfg.faq || {}));
            })
            .catch(function (err) {
                self.typing(false);
                self.busy = false;
                self.pending.unshift(text);
                var row = self.say(
                    "bot",
                    (err && err.message) || "Không gửi được tin nhắn lúc này.",
                );
                row.classList.add("tc-row--error");
                var retry = el("button", "tc-chip", "Thử gửi lại");
                retry.type = "button";
                retry.addEventListener("click", function () {
                    retry.remove();
                    self.flush();
                });
                self.chips.innerHTML = "";
                self.chips.appendChild(retry);
            });
    };

    /* ================== WIDGET (bong bóng nổi) ================== */
    function mountWidget(root) {
        var fab = $("#tcFab", root),
            dial = $("#tcDial", root),
            panel = $("#tcPanel", root);
        if (!fab || !panel) return;
        var chat = null;

        var openPanel = function () {
            dial && dial.classList.remove("is-open");
            panel.hidden = false;
            requestAnimationFrame(function () {
                panel.classList.add("is-open");
            });
            root.classList.add("is-chat");
            fab.setAttribute("aria-expanded", "true");
            if (!chat) {
                chat = new Chat(panel, "widget");
                chat.onAction = function (a) {
                    if (a === "min" || a === "close") closePanel(a === "close");
                };
            }
            setTimeout(function () {
                chat.input.focus();
            }, 120);
        };
        var closePanel = function (reset) {
            panel.classList.remove("is-open");
            root.classList.remove("is-chat");
            fab.setAttribute("aria-expanded", "false");
            setTimeout(
                function () {
                    panel.hidden = true;
                },
                reduce ? 0 : 200,
            );
            if (reset) {
                safe(function () {
                    sessionStorage.removeItem(STORE);
                });
                panel.innerHTML = "";
                chat = null;
            }
            fab.focus();
        };
        var dialItems = function () {
            return Array.prototype.slice.call(
                dial.querySelectorAll(".tc-dial__item"),
            );
        };
        var toggleDial = function () {
            var open = !dial.classList.contains("is-open");
            dial.classList.toggle("is-open", open);
            fab.setAttribute("aria-expanded", open ? "true" : "false");
            // mở menu: chuyển tiêu điểm vào mục đầu để người dùng bàn phím/đọc màn hình thao tác tiếp được
            if (open) {
                setTimeout(function () {
                    var first = dialItems()[0];
                    if (first) first.focus();
                }, 60);
            }
        };
        if (dial)
            dial.addEventListener("keydown", function (e) {
                var items = dialItems(),
                    i = items.indexOf(document.activeElement);
                if (i < 0) return;
                var to = null;
                if (e.key === "ArrowDown") to = (i + 1) % items.length;
                else if (e.key === "ArrowUp")
                    to = (i - 1 + items.length) % items.length;
                else if (e.key === "Home") to = 0;
                else if (e.key === "End") to = items.length - 1;
                if (to === null) return;
                e.preventDefault();
                items[to].focus();
            });

        fab.addEventListener("click", function () {
            if (!panel.hidden) {
                closePanel(false);
                return;
            }
            // chỉ có kênh chat -> mở thẳng khung chat; có thêm Gọi/Zalo/Messenger -> mở danh sách
            if (dial && dial.children.length > 1) toggleDial();
            else openPanel();
        });
        root.addEventListener("click", function (e) {
            if (e.target.closest("[data-tc-open]")) openPanel();
        });
        document.addEventListener("click", function (e) {
            if (
                dial &&
                dial.classList.contains("is-open") &&
                !root.contains(e.target)
            ) {
                dial.classList.remove("is-open");
                fab.setAttribute("aria-expanded", "false");
            }
        });
        document.addEventListener("keydown", function (e) {
            if (e.key !== "Escape") return;
            if (!panel.hidden) closePanel(false);
            else if (dial && dial.classList.contains("is-open")) {
                dial.classList.remove("is-open");
                fab.focus();
            }
        });
    }

    /* ================== KHỞI TẠO ================== */
    function init() {
        var w = document.getElementById("tcWidget");
        if (w) mountWidget(w);
        var page = document.querySelector('[data-travel-chat="page"]');
        if (page) new Chat(page, "page");
    }

    window.TravelChat = {
        registerAdapter: function (name, impl) {
            adapters[name] = impl;
        },
        use: function (name) {
            if (adapters[name]) current = name;
        },
    };

    if (document.readyState === "loading")
        document.addEventListener("DOMContentLoaded", init);
    else init();
})();
