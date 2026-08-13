// Chart Tabbed Panel Component

export function initTabbedPanel(): void {
  document.querySelectorAll('.tabs-hd .tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const tab = btn.getAttribute('data-tab');
      if (!tab) return;

      // Update buttons
      document.querySelectorAll('.tabs-hd .tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      // Update panels
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      document.getElementById(`tab-${tab}`)?.classList.add('active');
    });
  });
}

export function initChartTabs(): void {
  const proTab = document.getElementById('chartTabPro');
  const fastTab = document.getElementById('chartTabFast');
  const tvWrap = document.getElementById('tvChartWrap');
  const candleChart = document.getElementById('candleChart');

  proTab?.addEventListener('click', () => {
    proTab.classList.add('active');
    fastTab?.classList.remove('active');
    tvWrap?.classList.remove('hidden');
    candleChart?.classList.add('hidden');
  });

  fastTab?.addEventListener('click', () => {
    fastTab.classList.add('active');
    proTab?.classList.remove('active');
    tvWrap?.classList.add('hidden');
    candleChart?.classList.remove('hidden');
  });
}