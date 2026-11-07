'use strict';
const q = new URLSearchParams(location.search);
  document.getElementById('device').textContent = q.get('device') || '';
  if (q.get('message')) document.getElementById('msg').textContent = q.get('message');
  document.getElementById('go').onclick = async () => {
    const r = await window.examDesktop.setup(document.getElementById('url').value.trim());
    const m = document.getElementById('msg');
    if (!r.ok) { m.className = 'msg'; m.textContent = r.message; return; }
    m.className = r.status === 'approved' ? 'ok' : 'msg';
    m.textContent = r.status === 'approved' ? `Connected to ${r.institution?.name ?? 'the platform'}. This computer is approved.` : 'Connected. Waiting for the ICT office to approve this computer — then open the app again.';
    document.getElementById('cont').hidden = r.status !== 'approved';
  };
  document.getElementById('cont').onclick = () => window.examDesktop.continue();
