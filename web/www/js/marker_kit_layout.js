(function () {
  'use strict';
  const table = document.getElementById('markerKitTable');
  if (!table) return;

  async function saveRow(row) {
    const payload = {
      marker_id: Number(row.dataset.markerId),
      marker_size_m: Number(row.querySelector('[name="marker_size_m"]').value),
      center_x_m: Number(row.querySelector('[name="center_x_m"]').value),
      center_y_m: Number(row.querySelector('[name="center_y_m"]').value),
      center_z_m: Number(row.querySelector('[name="center_z_m"]').value),
      yaw_deg: Number(row.querySelector('[name="yaw_deg"]').value),
      pitch_deg: Number(row.querySelector('[name="pitch_deg"]').value),
      roll_deg: Number(row.querySelector('[name="roll_deg"]').value),
      surface_type: row.querySelector('[name="surface_type"]').value || null,
      note: row.querySelector('[name="note"]').value || null,
    };

    const status = row.querySelector('.js-save-status');
    status.textContent = 'Saving...';
    status.className = 'js-save-status text-muted';

    try {
      const r = await fetch('/api/marker_kit_layout.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await r.json();
      if (!r.ok || !data.ok) throw new Error(data.error || ('HTTP ' + r.status));
      status.textContent = 'Saved';
      status.className = 'js-save-status text-success';
      row.classList.add('table-success');
      setTimeout(() => row.classList.remove('table-success'), 1000);
    } catch (e) {
      status.textContent = 'Error: ' + (e.message || 'unknown');
      status.className = 'js-save-status text-danger';
    }
  }

  table.querySelectorAll('.js-save-row').forEach((btn) => {
    btn.addEventListener('click', () => saveRow(btn.closest('tr')));
  });

  const seedBtn = document.getElementById('markerKitSeedBtn');
  if (seedBtn) {
    seedBtn.addEventListener('click', async () => {
      seedBtn.disabled = true;
      try {
        const r = await fetch('/tools/seed_marker_kit_v1.php', { credentials: 'same-origin' });
        if (!r.ok) throw new Error('seed_failed');
        window.location.reload();
      } catch (e) {
        alert('Seed error: ' + (e.message || 'unknown'));
      } finally { seedBtn.disabled = false; }
    });
  }
})();
