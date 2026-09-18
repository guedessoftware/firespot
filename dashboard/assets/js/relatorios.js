// dashboard/assets/js/relatorios.js
(function () {
  const main = document.querySelector('body[data-page="relatorios"]');
  if (!main) return;

  // Charts variables
  let chartHost = null;
  let chartNas = null;
  let hostExtras = [];
  let nasExtras = [];

  // Initialize charts on page load
  document.addEventListener('DOMContentLoaded', () => {
    initCharts();
    updateChartsReports();
    document.getElementById('periodo-host')?.addEventListener('change', updateChartsReports);
    document.getElementById('reports-chart-refresh')?.addEventListener('click', updateChartsReports);
  });

  function initCharts() {
    // Chart for Host access
    const ctxHost = document.getElementById('chartAcessosHost');
    if (ctxHost) {
      chartHost = new Chart(ctxHost, {
        type: 'bar',
        data: {
          labels: [],
          datasets: [{
            label: 'Acessos',
            data: [],
            backgroundColor: 'rgba(54, 162, 235, 0.8)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const value = context.parsed.y ?? context.parsed;
                  return `${context.dataset.label}: ${value.toLocaleString()}`;
                },
                afterLabel: function(context) {
                  const extra = hostExtras?.[context.dataIndex];
                  if (!extra) return '';
                  const lines = [];
                  if (extra.usuarios) {
                    lines.push(`Usuários únicos: ${extra.usuarios.toLocaleString()}`);
                  }
                  if (extra.dispositivos) {
                    lines.push(`Dispositivos únicos: ${extra.dispositivos.toLocaleString()}`);
                  }
                  if (extra.nas) {
                    lines.push(`NAS: ${extra.nas}`);
                  }
                  return lines.join('\n');
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: {
                precision: 0
              }
            }
          }
        }
      });
    }

    // Chart for NAS access (pie chart)
    const ctxNas = document.getElementById('chartAcessosNas');
    if (ctxNas) {
      chartNas = new Chart(ctxNas, {
        type: 'doughnut',
        data: {
          labels: [],
          datasets: [{
            data: [],
            backgroundColor: [
              'rgba(255, 99, 132, 0.8)',
              'rgba(54, 162, 235, 0.8)',
              'rgba(255, 205, 86, 0.8)',
              'rgba(75, 192, 192, 0.8)',
              'rgba(153, 102, 255, 0.8)',
              'rgba(255, 159, 64, 0.8)',
              'rgba(199, 199, 199, 0.8)',
              'rgba(83, 102, 255, 0.8)'
            ],
            borderColor: [
              'rgba(255, 99, 132, 1)',
              'rgba(54, 162, 235, 1)',
              'rgba(255, 205, 86, 1)',
              'rgba(75, 192, 192, 1)',
              'rgba(153, 102, 255, 1)',
              'rgba(255, 159, 64, 1)',
              'rgba(199, 199, 199, 1)',
              'rgba(83, 102, 255, 1)'
            ],
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              position: 'bottom'
            },
            tooltip: {
              callbacks: {
                label: function(context) {
                  const value = context.parsed;
                  return `${context.dataset.label || 'Acessos'}: ${value.toLocaleString()}`;
                },
                afterLabel: function(context) {
                  const extra = nasExtras?.[context.dataIndex];
                  if (!extra) return '';
                  const details = [];
                  if (extra.usuarios) details.push(`Usuários únicos: ${extra.usuarios.toLocaleString()}`);
                  if (extra.dispositivos) details.push(`Dispositivos únicos: ${extra.dispositivos.toLocaleString()}`);
                  return details.length > 0 ? details.join('\n') : '';
                }
              }
            }
          }
        }
      });
    }
  }

  async function updateChartsReports() {
    const periodo = document.getElementById('periodo-host')?.value || 30;
    
    try {
      const response = await fetch(`api/charts_report.php?periodo=${periodo}`);
      const data = await response.json();
      
      if (!data.ok) {
        console.error('Error loading charts data:', data.error);
        return;
      }

      updateHostChart(data.acessosPorHost);
      updateNasChart(data.acessosPorNas);
      
    } catch (error) {
      console.error('Error fetching charts data:', error);
    }
  }

  function updateHostChart(hostData) {
    if (!chartHost || !hostData) return;

    const topItems = hostData.slice(0, 10);
    hostExtras = topItems.map(item => ({
      usuarios: Number(item.usuarios_unicos) || 0,
      dispositivos: Number(item.dispositivos_unicos) || 0,
      nas: item.nas_label || ''
    }));

    chartHost.data.labels = topItems.map(item => item.label);
    chartHost.data.datasets[0].data = topItems.map(item => Number(item.total_acessos) || 0);
    chartHost.update();
  }

  function updateNasChart(nasData) {
    if (!chartNas || !nasData) return;

    nasExtras = nasData.map(item => ({
      usuarios: Number(item.usuarios_unicos) || 0,
      dispositivos: Number(item.dispositivos_unicos) || 0
    }));

    chartNas.data.labels = nasData.map(item => item.label);
    chartNas.data.datasets[0].data = nasData.map(item => Number(item.total_acessos) || 0);
    chartNas.update();
  }

})();
