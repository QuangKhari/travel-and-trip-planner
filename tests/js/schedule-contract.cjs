/* Chạy lõi xếp giờ phía trình duyệt (planner-geo.js + planner-schedule.js) trên dữ liệu từ stdin, in hợp đồng lịch ra stdout.
 * Dùng bởi tests/Feature/PlannerContractTest.php: cùng input, máy chủ (DaySchedule) và trình duyệt phải ra cùng con số.
 * stdin: {"rules": PlannerRules::client(), "days": {"1": [items...], ...}}
 */
"use strict";
const fs = require("fs");
const path = require("path");
const vm = require("vm");

const js = path.join(__dirname, "..", "..", "public", "clients", "assets", "js");
const ctx = { globalThis: null, Math, JSON, Object, Array, RegExp, isFinite, parseFloat, String, Number, Date };
ctx.globalThis = ctx;
vm.createContext(ctx);
for (const f of ["planner-geo.js", "planner-schedule.js"]) vm.runInContext(fs.readFileSync(path.join(js, f), "utf8"), ctx, { filename: f });

const input = JSON.parse(fs.readFileSync(0, "utf8"));
const G = ctx.TpGeo.configure(input.rules.route);
const S = ctx.TpSchedule.create(input.rules, G);
const out = {};
for (const d of Object.keys(input.days)) { const list = Array.isArray(input.days[d]) ? input.days[d] : []; out[d] = S.contract(+d, S.day(list, { dow: (input.dows || {})[d] || null, ctx: (input.ctxs || {})[d] || null })); }
// ngữ cảnh từng ngày từ chỗ ở / vé đi về (TpSchedule.dayCtx), cùng quy tắc TripLogistics::dayContext
if (input.ctxProbe) { const p = input.ctxProbe; out._ctx = {}; for (let d = 1; d <= p.days; d++) out._ctx[d] = S.dayCtx(p.raw, p.start, d, p.days); }
out._slotOf = (input.probeMinutes || []).map((m) => S.slotOf(m));
out._kinds = (input.probeKinds || []).map((it) => S.kindOf(it));
process.stdout.write(JSON.stringify(out));
