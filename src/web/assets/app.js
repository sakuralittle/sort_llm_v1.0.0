/* 公文分辦結果頁 — 前端互動（純 JS，無依賴） */
(function () {
  const rows = window.__ROWS__ || [];
  const tbody = document.querySelector('#grid tbody');
  if (!tbody) return; // 無資料時不渲染

  const q = document.getElementById('q');
  const statusFilter = document.getElementById('statusFilter');
  const countEl = document.getElementById('count');
  const chips = Array.from(document.querySelectorAll('.chip'));

  let kw = '';
  let stat = '';
  let unit = ''; // '' = 全部

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function statusBadge(s) {
    if (s === 'ok')      return '<span class="badge ok">成功</span>';
    if (s === 'unknown') return '<span class="badge warn">未知</span>';
    if (s === 'error')   return '<span class="badge err">錯誤</span>';
    return '<span class="badge muted">' + escapeHtml(s) + '</span>';
  }

  function unitBadge(u, raw) {
    if (u) return '<span class="badge unit">' + escapeHtml(u) + '</span>';
    if (raw) return '<span class="badge muted" title="' + escapeHtml(raw) + '">原始：' + escapeHtml(raw.slice(0, 12)) + '</span>';
    return '<span class="badge muted">-</span>';
  }

  function render() {
    const kwLower = kw.trim().toLowerCase();
    const out = [];
    let shown = 0;
    rows.forEach((r, idx) => {
      if (stat && r.status !== stat) return;
      if (unit && r.unit !== unit) return;
      if (kwLower) {
        const hay = (r.doc_id + ' ' + r.org + ' ' + r.subject + ' ' + r.kind).toLowerCase();
        if (hay.indexOf(kwLower) < 0) return;
      }
      shown++;
      out.push(
        '<tr>' +
          '<td>' + shown + '</td>' +
          '<td class="docid">' + escapeHtml(r.doc_id) + '</td>' +
          '<td>' + escapeHtml(r.date) + '</td>' +
          '<td>' + escapeHtml(r.org) + '</td>' +
          '<td class="subject">' + escapeHtml(r.subject) + '</td>' +
          '<td>' + unitBadge(r.unit, r.raw) + '</td>' +
          '<td>' + statusBadge(r.status) + '</td>' +
          '<td class="ms">' + (r.ms ? r.ms.toFixed(0) + ' ms' : '-') + '</td>' +
        '</tr>'
      );
    });
    tbody.innerHTML = out.join('') || '<tr><td colspan="8" style="text-align:center;color:#6b7280;padding:24px">無符合條件的資料</td></tr>';
    if (countEl) countEl.textContent = '顯示 ' + shown + ' / ' + rows.length + ' 筆';
  }

  if (q) q.addEventListener('input', e => { kw = e.target.value; render(); });
  if (statusFilter) statusFilter.addEventListener('change', e => { stat = e.target.value; render(); });

  chips.forEach(c => {
    c.addEventListener('click', () => {
      chips.forEach(x => x.classList.remove('active'));
      c.classList.add('active');
      unit = c.dataset.unit || '';
      render();
    });
  });

  render();
})();
