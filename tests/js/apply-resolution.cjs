/* Chạy ĐÚNG mã trình duyệt (planner-resolve.js + planner-save.js) để áp dụng một phương án của TripResolver
 * rồi dựng thân yêu cầu lưu. Dùng bởi tests/Feature/PlannerInvariantTest (xem trước = áp dụng, qua chính mã JS).
 * stdin: {items:[itemForClient...], rain:{}, changes:[], meta:{}, rev, rainMax}
 * stdout: thân yêu cầu POST /len-ke-hoach/{id}/luu
 */
"use strict";
const fs = require("fs");
const path = require("path");
const vm = require("vm");

const js = path.join(__dirname, "..", "..", "public", "clients", "assets", "js");
const ctx = { Math, JSON, Object, Array, String, Number, isFinite };
ctx.globalThis = ctx;
vm.createContext(ctx);
for (const f of ["planner-resolve.js", "planner-save.js"]) vm.runInContext(fs.readFileSync(path.join(js, f), "utf8"), ctx, { filename: f });

const input = JSON.parse(fs.readFileSync(0, "utf8"));
let seq = 0;
// cùng các trường với newItem() trong planner.js
const newItem = (o) => ({
    uid: ++seq, type: o.type, refId: o.refId == null ? null : o.refId, name: o.name, sub: o.sub || "", url: o.url || null,
    day: o.day == null ? null : o.day, note: o.note || "", cost: o.cost == null ? null : +o.cost, slot: null, reasons: [],
    lat: o.lat == null ? null : o.lat, lng: o.lng == null ? null : o.lng, when: null, source: null,
    startMin: null, durationMin: null, defDur: o.defDur || null, image: o.image || null, locked: false, outdoor: o.outdoor == null ? null : o.outdoor,
});
const items = input.items.map((x) => Object.assign({ uid: ++seq }, x));
const rain = input.rain || {};
const r = ctx.TpResolve.apply(items, rain, input.changes, newItem, input.rainMax || 4);
if (!r.ok) { process.stderr.write("apply failed"); process.exit(2); }
process.stdout.write(JSON.stringify(ctx.TpSave.body({ meta: input.meta, items, rain, rev: input.rev })));
