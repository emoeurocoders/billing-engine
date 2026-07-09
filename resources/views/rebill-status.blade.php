<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rebills &mdash; {{ ucfirst($vertical) }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; font-size: 14px; }
        .header { background: #1a1a2e; color: #fff; padding: 16px 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .header h1 { font-size: 18px; font-weight: 600; }
        .header h1 .sub { color: #8b8ba7; font-weight: 400; margin-left: 8px; }
        .nav { display: flex; align-items: center; gap: 8px; }
        .nav button, .nav a { background: #2a2a4e; color: #fff; border: none; border-radius: 6px; padding: 7px 12px; font-size: 13px; cursor: pointer; text-decoration: none; }
        .nav button:hover { background: #3a3a5e; }
        .nav button:disabled { opacity: .4; cursor: not-allowed; }
        .nav input[type=date] { background: #2a2a4e; color: #fff; border: 1px solid #3a3a5e; border-radius: 6px; padding: 6px 10px; font-size: 13px; }
        .header-right { display: flex; align-items: center; gap: 12px; font-size: 13px; color: #aaa; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
        .badge-live { background: #22c55e; color: #fff; }
        .badge-hist { background: #6b7280; color: #fff; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px 24px; }
        .progress-wrap { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .progress-top { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; color: #555; }
        .progress-bar { height: 12px; background: #eee; border-radius: 6px; overflow: hidden; }
        .progress-fill { height: 100%; background: linear-gradient(90deg,#16a34a,#22c55e); border-radius: 6px; transition: width .4s; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: #fff; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .card-label { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 6px; }
        .card-value { font-size: 30px; font-weight: 700; font-family: 'SF Mono','Fira Code',monospace; }
        .card-sub { font-size: 13px; color: #888; margin-top: 4px; font-family: 'SF Mono',monospace; }
        .green { color: #16a34a; } .blue { color: #2563eb; } .red { color: #dc2626; } .orange { color: #ea580c; } .gray { color: #6b7280; }
        .card.alert { outline: 2px solid #fca5a5; }
        .card.warn { outline: 2px solid #fdba74; }
        .foot { color: #999; font-size: 12px; text-align: center; margin-top: 8px; }
        .section { margin-top: 4px; margin-bottom: 20px; }
        .section-title { font-size: 15px; font-weight: 600; margin-bottom: 10px; color: #555; }
        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        thead th { background: #1a1a2e; color: #fff; padding: 9px 12px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; font-weight: 600; }
        thead th.num, tbody td.num { text-align: right; }
        tbody td { padding: 7px 12px; border-bottom: 1px solid #eee; font-family: 'SF Mono','Fira Code',monospace; font-size: 13px; }
        tbody tr:last-child td { border-bottom: none; }
        tr.past td { color: #9ca3af; }
        tr.now td { background: #fffbeb; font-weight: 600; color: #333; }
        tr.future td:first-child { color: #2563eb; }
        .pill { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; padding: 2px 7px; border-radius: 10px; margin-left: 8px; }
        .pill-now { background: #f59e0b; color: #fff; }
    </style>
</head>
<body data-prefix="{{ $prefix }}">
    <div class="header">
        <h1>Rebills <span class="sub">{{ ucfirst($vertical) }}</span></h1>
        <div class="nav">
            <button id="prev" title="Previous day">&larr;</button>
            <input type="date" id="datePicker">
            <button id="next" title="Next day">&rarr;</button>
            <button id="todayBtn">Today</button>
        </div>
        <div class="header-right">
            <span id="liveBadge" class="badge badge-live">Live</span>
            <span id="tz"></span>
            <span>updated <span id="updated">&mdash;</span></span>
        </div>
    </div>

    <div class="container">
        <div class="progress-wrap">
            <div class="progress-top">
                <span><strong id="progressPct">0%</strong> processed</span>
                <span id="progressText">&mdash;</span>
            </div>
            <div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:0%"></div></div>
        </div>

        <div class="cards">
            <div class="card"><div class="card-label">Billed</div><div class="card-value green" id="c_billed">0</div><div class="card-sub" id="c_billed_amt">$0.00</div></div>
            <div class="card"><div class="card-label">Declined</div><div class="card-value gray" id="c_declined">0</div><div class="card-sub" id="c_rate">&mdash;</div></div>
            <div class="card"><div class="card-label">Killed (neg-db)</div><div class="card-value gray" id="c_killed">0</div></div>
            <div class="card"><div class="card-label">Processed</div><div class="card-value blue" id="c_processed">0</div></div>
            <div class="card" id="card_duenow"><div class="card-label">Due now</div><div class="card-value" id="c_due_now">0</div><div class="card-sub">pending &middot; time arrived</div></div>
            <div class="card"><div class="card-label">Scheduled today</div><div class="card-value gray" id="c_scheduled">0</div><div class="card-sub" id="c_scheduled_amt">$0.00</div></div>
            {{-- <div class="card" id="card_parked"><div class="card-label">Parked &rarr; next cycle</div><div class="card-value orange" id="c_parked">0</div><div class="card-sub">never charged (recovery watch)</div></div> --}}
            <div class="card" id="card_inactive_mid"><div class="card-label">Inactive MID (no redirect)</div><div class="card-value orange" id="c_inactive_mid">0</div><div class="card-sub" id="c_inactive_mid_sub">will be skipped today</div></div>
        </div>

        <div class="section">
            <div class="section-title">By hour (<span id="hourTz">MST</span>) &mdash; past = processed, upcoming = scheduled</div>
            <table>
                <thead><tr>
                    <th>Hour</th>
                    <th class="num">Scheduled</th>
                    <th class="num">$ Scheduled</th>
                    <th class="num">Processed</th>
                    <th class="num">Billed</th>
                    <th class="num">$ Billed</th>
                </tr></thead>
                <tbody id="hourlyBody"></tbody>
            </table>
        </div>

        <div class="foot">Read-only. Timezone <span id="tzfoot"></span>. Auto-refreshes every 20s when viewing today.</div>
    </div>

    <script>
    (function () {
        const prefix = document.body.dataset.prefix;
        const keyParam = new URLSearchParams(location.search).get('key');
        const picker = document.getElementById('datePicker');
        let timer = null;

        function todayStr() { return new Date().toISOString().slice(0, 10); }
        function apiUrl(date) {
            let u = '/' + prefix + '/api/data?date=' + encodeURIComponent(date);
            if (keyParam) u += '&key=' + encodeURIComponent(keyParam);
            return u;
        }
        function shiftDate(str, days) {
            const d = new Date(str + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + days);
            return d.toISOString().slice(0, 10);
        }
        const money = n => '$' + Number(n || 0).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        const num = n => Number(n || 0).toLocaleString('en-US');
        const cell = v => v ? v : '<span style="color:#ccc">&middot;</span>';

        function renderHourly(rows) {
            const tb = document.getElementById('hourlyBody');
            if (!rows || !rows.length) { tb.innerHTML = '<tr><td colspan="6" style="color:#999">No rebills for this day.</td></tr>'; return; }
            tb.innerHTML = rows.map(r => {
                const pill = r.state === 'now' ? ' <span class="pill pill-now">now</span>' : '';
                return '<tr class="' + r.state + '">'
                    + '<td>' + r.hour + pill + '</td>'
                    + '<td class="num">' + cell(r.scheduled && num(r.scheduled)) + '</td>'
                    + '<td class="num">' + cell(r.sched_amt && money(r.sched_amt)) + '</td>'
                    + '<td class="num">' + cell(r.processed && num(r.processed)) + '</td>'
                    + '<td class="num">' + cell(r.billed && num(r.billed)) + '</td>'
                    + '<td class="num">' + cell(r.billed_amt && money(r.billed_amt)) + '</td>'
                    + '</tr>';
            }).join('');
        }

        function render(d) {
            const c = d.cards;
            document.getElementById('c_billed').textContent = num(c.billed.count);
            document.getElementById('c_billed_amt').textContent = money(c.billed.amount);
            document.getElementById('c_declined').textContent = num(c.declined.count);
            document.getElementById('c_rate').textContent = c.approval_rate + '% appr';
            document.getElementById('c_killed').textContent = num(c.killed.count);
            document.getElementById('c_processed').textContent = num(c.processed.count);
            document.getElementById('c_due_now').textContent = num(c.due_now.count);
            document.getElementById('c_scheduled').textContent = num(c.scheduled.count);
            document.getElementById('c_scheduled_amt').textContent = money(c.scheduled.amount);
            document.getElementById('c_inactive_mid').textContent = num(c.inactive_mid.count);
            document.getElementById('c_inactive_mid_sub').textContent = money(c.inactive_mid.amount) + ' · ' + num(c.inactive_mid.mids) + ' MIDs · skipped today';

            // Due-now is the backlog to watch: green when clear, orange normal churn,
            // red only if it piles up well past one dispatch batch.
            const dn = c.due_now.count;
            document.getElementById('card_duenow').className = 'card' + (dn > 2000 ? ' alert' : '');
            document.getElementById('c_due_now').className = 'card-value ' + (dn > 2000 ? 'red' : (dn > 0 ? 'orange' : 'green'));
            const im = c.inactive_mid.count;
            document.getElementById('card_inactive_mid').className = 'card' + (im > 0 ? ' warn' : '');
            document.getElementById('c_inactive_mid').className = 'card-value ' + (im > 0 ? 'orange' : 'green');

            document.getElementById('progressPct').textContent = d.progress + '%';
            document.getElementById('progressFill').style.width = Math.min(100, d.progress) + '%';
            document.getElementById('progressText').textContent = num(c.processed.count) + ' processed · ' + num(c.scheduled.count) + ' scheduled today' + (dn > 0 ? ' · ' + num(dn) + ' due now' : '');

            document.getElementById('updated').textContent = (d.updated_at || '').slice(11, 19) || '—';
            document.getElementById('tz').textContent = d.tz;
            document.getElementById('tzfoot').textContent = d.tz;
            document.getElementById('hourTz').textContent = d.tz;
            renderHourly(d.hourly);
            const live = d.is_today;
            const badge = document.getElementById('liveBadge');
            badge.textContent = live ? 'Live' : 'History';
            badge.className = 'badge ' + (live ? 'badge-live' : 'badge-hist');
            document.getElementById('next').disabled = (picker.value >= todayStr());
        }

        function load(date) {
            picker.value = date;
            fetch(apiUrl(date)).then(r => r.json()).then(render).catch(() => {});
            if (timer) clearInterval(timer);
            if (date === todayStr()) timer = setInterval(() => fetch(apiUrl(date)).then(r => r.json()).then(render).catch(() => {}), 20000);
        }

        document.getElementById('prev').onclick = () => load(shiftDate(picker.value, -1));
        document.getElementById('next').onclick = () => { if (picker.value < todayStr()) load(shiftDate(picker.value, 1)); };
        document.getElementById('todayBtn').onclick = () => load(todayStr());
        picker.onchange = () => load(picker.value);

        const start = new URLSearchParams(location.search).get('date') || todayStr();
        load(start);
    })();
    </script>
</body>
</html>
