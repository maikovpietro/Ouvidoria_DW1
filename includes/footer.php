  <footer class="main-footer">
    <div class="footer-logos">
      <img src="<?= $_base ?>assets/images/logo-escola.png" alt="EEEP Dom Walfrido" onerror="this.style.display='none'">
      <img src="<?= $_base ?>assets/images/logo-ceara.png" alt="Governo do Ceará" onerror="this.style.display='none'">
    </div>
    <div class="footer-center">
      <p class="footer-name">EEEP Dom Walfrido Teixeira Vieira</p>
      <p>Ouvidoria do Grêmio Escolar</p>
      <p>Av. Dr. Paulo de Almeida Sanford — Colina Boa Vista, Sobral - CE</p>
    </div>
    <div class="footer-right"><p>© 2026 Grêmio Escolar Dom Walfrido</p></div>
  </footer>
</main>

<!-- Bootstrap 5 JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?= $_base ?>assets/js/app.js"></script>
<!-- Notificações JS -->
<script>
(function() {
  const bell = document.getElementById('notifDropdown');
  if (!bell) return;

  const lista = document.getElementById('notifLista');
  let carregado = false;

  bell.addEventListener('show.bs.dropdown', function () {
    if (carregado) return;
    carregado = true;
    const coluna = bell.dataset.coluna;
    const id     = bell.dataset.id;

    fetch((bell.dataset.notifUrl || 'notificacoes.php') + '?ajax=1&coluna=' + encodeURIComponent(coluna) + '&id=' + encodeURIComponent(id))
      .then(r => r.json())
      .then(data => {
        if (!data.length) {
          lista.innerHTML = '<div class="text-center py-3 text-muted small"><i class="fa-solid fa-bell-slash me-1"></i>Nenhuma notificação</div>';
          return;
        }
        lista.innerHTML = data.map(n => `
          <a href="${n.link || '#'}" class="notif-item ${n.lida == '0' ? 'notif-nao-lida' : ''}">
            <div class="notif-item-icon"><i class="fa-solid ${iconeTipo(n.tipo)}"></i></div>
            <div class="notif-item-body">
              <div class="notif-item-titulo">${escHtml(n.titulo)}</div>
              <div class="notif-item-msg">${escHtml(n.mensagem)}</div>
              <div class="notif-item-data">${n.criado_em_fmt}</div>
            </div>
          </a>
        `).join('');
      })
      .catch(() => {
        lista.innerHTML = '<div class="text-center py-3 text-muted small">Erro ao carregar.</div>';
      });
  });

  function iconeTipo(tipo) {
    const m = {nova_resposta:'fa-comment', nova_manifestacao:'fa-paper-plane', status_atualizado:'fa-circle-check', geral:'fa-bell'};
    return m[tipo] || 'fa-bell';
  }

  function escHtml(s) {
    const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
  }

  // Marcar como lidas ao abrir
  bell.addEventListener('shown.bs.dropdown', function () {
    fetch((bell.dataset.notifUrl || 'notificacoes.php') + '?marcar_lidas=1&ajax=1&coluna=' + bell.dataset.coluna + '&id=' + bell.dataset.id);
    const badge = bell.querySelector('.notif-badge');
    if (badge) badge.remove();
  });
})();
</script>
</body>
</html>
