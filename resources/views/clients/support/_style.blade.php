<style>
    .sp { padding: 130px 0 70px; background: #f6faf9; min-height: 70vh; }
    .sp-wrap { max-width: 1060px; margin: 0 auto; padding: 0 16px; }
    .sp-head h1 { font-size: 28px; font-weight: 700; color: #0f2f3a; margin: 0 0 6px; }
    .sp-head p { color: #557080; margin: 0 0 24px; max-width: 640px; }
    .sp-grid { display: grid; grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr); gap: 22px; align-items: start; }
    .sp-card { background: #fff; border: 1px solid #e3ecef; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 47, 58, .05); }
    .sp-card__h { padding: 16px 20px; border-bottom: 1px solid #e3ecef; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .sp-card__h h2 { margin: 0; font-size: 17px; font-weight: 700; color: #0f2f3a; }
    .sp-card__b { padding: 18px 20px; }
    .sp-list { list-style: none; margin: 0; padding: 0; }
    .sp-list a { display: flex; gap: 12px; align-items: center; padding: 14px 20px; border-bottom: 1px solid #eef3f5; color: inherit; text-decoration: none; }
    .sp-list li:last-child a { border-bottom: 0; }
    .sp-list a:hover { background: #f4fbfa; }
    .sp-list b { display: block; color: #0f2f3a; }
    .sp-list small { color: #6b8592; }
    .sp-badge { margin-left: auto; flex: none; padding: 3px 10px; border-radius: 99px; font-size: 12px; font-weight: 600; }
    .sp-badge--open { background: #fef3c7; color: #92400e; } .sp-badge--in_progress { background: #dbeafe; color: #1d4ed8; }
    .sp-badge--answered { background: #dcfce7; color: #166534; } .sp-badge--closed { background: #eef2f6; color: #475569; }
    .sp-dot { width: 8px; height: 8px; border-radius: 50%; background: #168F99; flex: none; }
    .sp-f { display: grid; gap: 14px; }
    .sp-f label { font-weight: 600; font-size: 13.5px; color: #0f2f3a; margin: 0 0 6px; display: block; }
    .sp-f input, .sp-f select, .sp-f textarea { width: 100%; border: 1px solid #cfdde2; border-radius: 10px; padding: 10px 12px; font-size: 15px; background: #fff; }
    .sp-f textarea { min-height: 130px; resize: vertical; }
    .sp-f input:focus, .sp-f select:focus, .sp-f textarea:focus { outline: 0; border-color: #168F99; box-shadow: 0 0 0 4px rgba(13, 148, 136, .12); }
    .sp-f .sp-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .sp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px 20px; border: 0; border-radius: 10px; background: #168F99; color: #fff; font-weight: 700; cursor: pointer; text-decoration: none; }
    .sp-btn:hover { background: #0f766e; color: #fff; }
    .sp-btn--ghost { background: #fff; color: #0f2f3a; border: 1px solid #cfdde2; }
    .sp-btn--ghost:hover { background: #f4fbfa; color: #0f2f3a; }
    .sp-err { color: #b91c1c; font-size: 13px; margin-top: 4px; }
    .sp-empty { padding: 34px 20px; text-align: center; color: #6b8592; }
    .sp-thread { display: grid; gap: 14px; }
    .sp-msg { max-width: 82%; }
    .sp-msg__b { padding: 12px 16px; border-radius: 14px; background: #f1f5f7; white-space: pre-wrap; color: #0f2f3a; }
    .sp-msg small { display: block; margin-top: 4px; color: #6b8592; font-size: 12px; }
    .sp-msg--me { margin-left: auto; text-align: right; }
    .sp-msg--me .sp-msg__b { background: #168F99; color: #fff; text-align: left; border-bottom-right-radius: 4px; }
    .sp-msg--staff .sp-msg__b { border-bottom-left-radius: 4px; border: 1px solid #ccebe7; background: #f0fdfa; }
    .sp-alert { padding: 12px 16px; border-radius: 12px; margin-bottom: 16px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    @media (max-width: 860px) { .sp-grid { grid-template-columns: 1fr; } .sp { padding-top: 100px; } .sp-f .sp-2 { grid-template-columns: 1fr; } }
</style>
