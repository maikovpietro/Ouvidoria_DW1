document.addEventListener('DOMContentLoaded', () => {
  const hamburger = document.getElementById('hamburgerBtn');
  const drawer = document.getElementById('drawerSidebar');
  const drawerOverlay = document.getElementById('drawerOverlay');
  const drawerClose = document.getElementById('drawerClose');

  function openDrawer() {
    if (!drawer) return;
    hamburger?.classList.add('open');
    drawer.classList.add('open');
    drawerOverlay?.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeDrawer() {
    if (!drawer) return;
    hamburger?.classList.remove('open');
    drawer.classList.remove('open');
    drawerOverlay?.classList.remove('open');
    document.body.style.overflow = '';
  }

  hamburger?.addEventListener('click', () => {
    drawer?.classList.contains('open') ? closeDrawer() : openDrawer();
  });

  drawerOverlay?.addEventListener('click', closeDrawer);
  drawerClose?.addEventListener('click', closeDrawer);

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeDrawer();
  });

  const rawPage = window.location.pathname.split('/').pop();
  const page = (!rawPage || rawPage === '') ? 'index.php' : rawPage;

  function marcarAtivo(selector) {
    document.querySelectorAll(selector).forEach(item => {
      item.classList.remove('active');
      const href = item.getAttribute('href') || '';
      const basePage = href.split('#')[0].split('/').pop();
      const normalBase = (!basePage || basePage === '') ? 'index.php' : basePage;
      if (normalBase === page) item.classList.add('active');
    });
  }

  marcarAtivo('.topbar-link');
  marcarAtivo('.drawer-item');

  const authTabs = document.querySelectorAll('.auth-tab');
  const authPanels = document.querySelectorAll('.auth-panel');

  function abrirPainelAuth(panelName) {
    if (!panelName) return;
    authTabs.forEach(tab => tab.classList.toggle('active', tab.dataset.panel === panelName));
    authPanels.forEach(panel => panel.classList.toggle('active', panel.id === panelName));
  }

  authTabs.forEach(tab => {
    tab.addEventListener('click', () => abrirPainelAuth(tab.dataset.panel));
  });

  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('cadastro') === 'ok') {
    abrirPainelAuth('login');
  } else if (window.location.hash === '#cadastro') {
    abrirPainelAuth('cadastro');
  } else if (authTabs.length > 0) {
    abrirPainelAuth('login');
  }

  // Quando o usuário já está na página de login e clica em "Cadastrar-se"
  // na navbar, o hash muda mas DOMContentLoaded não dispara novamente.
  window.addEventListener('hashchange', () => {
    if (window.location.hash === '#cadastro') abrirPainelAuth('cadastro');
    else if (window.location.hash === '#login') abrirPainelAuth('login');
  });

  function aplicarMascaraCPF(input) {
    input.addEventListener('input', () => {
      let v = input.value.replace(/\D/g, '').slice(0, 11);
      if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
      else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
      else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
      input.value = v;
    });
  }

  document.querySelectorAll('[data-mask="cpf"]').forEach(aplicarMascaraCPF);

  const senhaInput = document.getElementById('cadSenha');
  const barra = document.getElementById('barraSenha');
  const labelSenha = document.getElementById('labelSenha');

  function calcularForcaSenha(senha) {
    let pts = 0;
    if (senha.length >= 8) pts++;
    if (senha.length >= 12) pts++;
    if (/[A-Z]/.test(senha)) pts++;
    if (/[a-z]/.test(senha)) pts++;
    if (/[0-9]/.test(senha)) pts++;
    if (/[^A-Za-z0-9]/.test(senha)) pts++;
    return pts;
  }

  function obterInfoForca(pts) {
    if (pts <= 1) return { label: 'Muito fraca', cor: '#e84040', pct: 16 };
    if (pts === 2) return { label: 'Fraca', cor: '#e88040', pct: 33 };
    if (pts === 3) return { label: 'Razoável', cor: '#e8c040', pct: 50 };
    if (pts === 4) return { label: 'Boa', cor: '#80c840', pct: 67 };
    if (pts === 5) return { label: 'Forte', cor: '#40b840', pct: 83 };
    return { label: 'Muito forte', cor: '#1a8c3a', pct: 100 };
  }

  if (senhaInput && barra && labelSenha) {
    senhaInput.addEventListener('input', () => {
      if (!senhaInput.value) {
        barra.style.width = '0';
        labelSenha.textContent = '';
        return;
      }
      const info = obterInfoForca(calcularForcaSenha(senhaInput.value));
      barra.style.width = info.pct + '%';
      barra.style.background = info.cor;
      labelSenha.textContent = info.label;
      labelSenha.style.color = info.cor;
    });
  }

  function setErro(campo, msg) {
    if (!campo) return;
    campo.classList.remove('campo-ok');
    campo.classList.add('campo-erro');
    let err = campo.parentElement.querySelector('.msg-erro');
    if (!err) {
      err = document.createElement('span');
      err.className = 'msg-erro';
      campo.parentElement.appendChild(err);
    }
    err.textContent = msg;
  }

  function setCampoOk(campo) {
    if (!campo) return;
    campo.classList.remove('campo-erro');
    campo.classList.add('campo-ok');
    const err = campo.parentElement.querySelector('.msg-erro');
    if (err) err.remove();
  }

  function limparErro(campo) {
    if (!campo) return;
    campo.classList.remove('campo-erro', 'campo-ok');
    const err = campo.parentElement.querySelector('.msg-erro');
    if (err) err.remove();
  }

  function validarEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test((email || '').trim());
  }

  function validarCPF(cpf) {
    cpf = (cpf || '').replace(/\D/g, '');
    if (cpf.length !== 11) return false;
    if (/^(\d)\1{10}$/.test(cpf)) return false;

    let soma = 0;
    for (let i = 0; i < 9; i++) soma += parseInt(cpf[i], 10) * (10 - i);
    let dig1 = (soma * 10) % 11;
    if (dig1 === 10) dig1 = 0;
    if (dig1 !== parseInt(cpf[9], 10)) return false;

    soma = 0;
    for (let i = 0; i < 10; i++) soma += parseInt(cpf[i], 10) * (11 - i);
    let dig2 = (soma * 10) % 11;
    if (dig2 === 10) dig2 = 0;
    return dig2 === parseInt(cpf[10], 10);
  }

  function bindValidation(campo, validator) {
    if (!campo) return;
    const run = () => validator();
    campo.addEventListener('input', run);
    campo.addEventListener('change', run);
    campo.addEventListener('blur', run);
  }

  // LOGIN
  const formLogin = document.getElementById('formLogin');
  if (formLogin) {
    const loginEmail = document.getElementById('loginEmail');
    const loginSenha = document.getElementById('loginSenha');

    const validarLoginEmail = () => {
      const valor = loginEmail.value.trim();
      if (!valor) { setErro(loginEmail, 'Informe seu e-mail.'); return false; }
      if (!validarEmail(valor)) { setErro(loginEmail, 'Informe um e-mail válido.'); return false; }
      setCampoOk(loginEmail); return true;
    };

    const validarLoginSenha = () => {
      const valor = loginSenha.value;
      if (!valor) { setErro(loginSenha, 'Informe sua senha.'); return false; }
      setCampoOk(loginSenha); return true;
    };

    bindValidation(loginEmail, validarLoginEmail);
    bindValidation(loginSenha, validarLoginSenha);

    formLogin.addEventListener('submit', (e) => {
      const ok = [validarLoginEmail(), validarLoginSenha()].every(Boolean);
      if (!ok) {
        e.preventDefault();
        formLogin.querySelector('.campo-erro')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  // CADASTRO
  const formCadastro = document.getElementById('formCadastro');
  if (formCadastro) {
    const cadNome = document.getElementById('cadNome');
    const cadCPF = document.getElementById('cadCPF');
    const cadPerfil = document.getElementById('cadPerfil');
    const cadEmail = document.getElementById('cadEmail');
    const cadSenha = document.getElementById('cadSenha');
    const cadConfSenha = document.getElementById('cadConfSenha');

    const validarCadNome = () => {
      if (cadNome.value.trim().length < 3) { setErro(cadNome, 'Digite seu nome completo.'); return false; }
      setCampoOk(cadNome); return true;
    };
    const validarCadCPF = () => {
      const val = cadCPF.value.trim();
      if (!val) { setErro(cadCPF, 'Informe seu CPF.'); return false; }
      // Só valida o algoritmo quando o CPF estiver completo (11 dígitos)
      const digits = val.replace(/\D/g, '');
      if (digits.length < 11) { setErro(cadCPF, 'CPF incompleto.'); return false; }
      if (!validarCPF(val)) { setErro(cadCPF, 'CPF inválido. Verifique os números.'); return false; }
      setCampoOk(cadCPF); return true;
    };
    // Valida em tempo real assim que o CPF atingir 11 dígitos
    cadCPF.addEventListener('input', () => {
      const digits = cadCPF.value.replace(/\D/g, '');
      if (digits.length === 11) validarCadCPF();
      else if (cadCPF.classList.contains('campo-erro') || cadCPF.classList.contains('campo-ok')) limparErro(cadCPF);
    });
    const validarCadPerfil = () => {
      if (!cadPerfil.value.trim()) { setErro(cadPerfil, 'Selecione seu perfil.'); return false; }
      setCampoOk(cadPerfil); return true;
    };
    const validarCadEmail = () => {
      if (!cadEmail.value.trim()) { setErro(cadEmail, 'Informe seu e-mail.'); return false; }
      if (!validarEmail(cadEmail.value)) { setErro(cadEmail, 'Informe um e-mail válido.'); return false; }
      setCampoOk(cadEmail); return true;
    };
    const validarCadSenha = () => {
      if (cadSenha.value.length < 8) { setErro(cadSenha, 'A senha deve ter pelo menos 8 caracteres.'); return false; }
      if (cadSenha.value.length > 72) { setErro(cadSenha, 'A senha deve ter no máximo 72 caracteres.'); return false; }
      setCampoOk(cadSenha); return true;
    };
    const validarCadConfSenha = () => {
      if (!cadConfSenha.value) { setErro(cadConfSenha, 'Confirme sua senha.'); return false; }
      if (cadConfSenha.value !== cadSenha.value) { setErro(cadConfSenha, 'As senhas não coincidem.'); return false; }
      setCampoOk(cadConfSenha); return true;
    };

    [ [cadNome, validarCadNome], [cadCPF, validarCadCPF], [cadPerfil, validarCadPerfil], [cadEmail, validarCadEmail], [cadSenha, validarCadSenha], [cadConfSenha, validarCadConfSenha] ]
      .forEach(([campo, fn]) => bindValidation(campo, fn));

    formCadastro.addEventListener('submit', (e) => {
      const ok = [validarCadNome(), validarCadCPF(), validarCadPerfil(), validarCadEmail(), validarCadSenha(), validarCadConfSenha()].every(Boolean);
      if (!ok) {
        e.preventDefault();
        formCadastro.querySelector('.campo-erro')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  // MINHA CONTA
  const formMinhaContaDados = document.getElementById('formMinhaContaDados');
  if (formMinhaContaDados) {
    const nome = document.getElementById('mcNome');
    const email = document.getElementById('mcEmail');

    const validarNome = () => {
      if (nome.value.trim().length < 3) { setErro(nome, 'Digite um nome válido.'); return false; }
      setCampoOk(nome); return true;
    };
    const validarEmailConta = () => {
      if (!email.value.trim()) { setErro(email, 'Informe seu e-mail.'); return false; }
      if (!validarEmail(email.value)) { setErro(email, 'Informe um e-mail válido.'); return false; }
      setCampoOk(email); return true;
    };

    bindValidation(nome, validarNome);
    bindValidation(email, validarEmailConta);

    formMinhaContaDados.addEventListener('submit', (e) => {
      const ok = [validarNome(), validarEmailConta()].every(Boolean);
      if (!ok) {
        e.preventDefault();
        formMinhaContaDados.querySelector('.campo-erro')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  const formMinhaContaSenha = document.getElementById('formMinhaContaSenha');
  if (formMinhaContaSenha) {
    const atual = document.getElementById('mcSenhaAtual');
    const nova = document.getElementById('mcNovaSenha');
    const conf = document.getElementById('mcConfirmarSenha');

    const validarAtual = () => {
      if (!atual.value) { setErro(atual, 'Informe sua senha atual.'); return false; }
      setCampoOk(atual); return true;
    };
    const validarNova = () => {
      if (nova.value.length < 8) { setErro(nova, 'A nova senha deve ter pelo menos 8 caracteres.'); return false; }
      setCampoOk(nova); return true;
    };
    const validarConf = () => {
      if (!conf.value) { setErro(conf, 'Confirme a nova senha.'); return false; }
      if (conf.value !== nova.value) { setErro(conf, 'As senhas não coincidem.'); return false; }
      setCampoOk(conf); return true;
    };

    [ [atual, validarAtual], [nova, validarNova], [conf, validarConf] ].forEach(([campo, fn]) => bindValidation(campo, fn));

    formMinhaContaSenha.addEventListener('submit', (e) => {
      const ok = [validarAtual(), validarNova(), validarConf()].every(Boolean);
      if (!ok) {
        e.preventDefault();
        formMinhaContaSenha.querySelector('.campo-erro')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  // RECUPERAR/RESETAR SENHA
  const formForgot = document.getElementById('formForgotPassword');
  if (formForgot) {
    const email = document.getElementById('email');
    const validar = () => {
      if (!email.value.trim()) { setErro(email, 'Informe seu e-mail.'); return false; }
      if (!validarEmail(email.value)) { setErro(email, 'Informe um e-mail válido.'); return false; }
      setCampoOk(email); return true;
    };
    bindValidation(email, validar);
    formForgot.addEventListener('submit', (e) => { if (!validar()) e.preventDefault(); });
  }

  const formReset = document.getElementById('formResetPassword');
  if (formReset) {
    const nova = document.getElementById('nova_senha');
    const conf = document.getElementById('confirmar_senha');
    const validarNova = () => {
      if (nova.value.length < 8) { setErro(nova, 'A senha deve ter pelo menos 8 caracteres.'); return false; }
      setCampoOk(nova); return true;
    };
    const validarConf = () => {
      if (!conf.value) { setErro(conf, 'Confirme a senha.'); return false; }
      if (conf.value !== nova.value) { setErro(conf, 'As senhas não coincidem.'); return false; }
      setCampoOk(conf); return true;
    };
    bindValidation(nova, validarNova);
    bindValidation(conf, validarConf);
    formReset.addEventListener('submit', (e) => {
      if (![validarNova(), validarConf()].every(Boolean)) e.preventDefault();
    });
  }

  // MANIFESTAÇÃO
  const formManifestacao = document.getElementById('formManifestacao');
  const modalConfirmacao = document.getElementById('modalConfirmacao');
  const btnAbrirConfirmacao = document.getElementById('btnAbrirConfirmacao');
  const btnFecharModal = document.getElementById('fecharModalConfirmacao');
  const btnVoltarEdicao = document.getElementById('btnVoltarEdicao');
  const btnConfirmarEnvio = document.getElementById('btnConfirmarEnvio');

  function valorOuPadrao(valor, padrao = 'Não informado') {
    const texto = (valor || '').trim();
    return texto !== '' ? texto : padrao;
  }

  function classeCurso(valor) {
    if (valor.includes('Informática')) return 'curso-informatica';
    if (valor.includes('Saúde Bucal')) return 'curso-saude';
    if (valor.includes('Energias')) return 'curso-energias';
    if (valor.includes('Enfermagem')) return 'curso-enfermagem';
    return 'curso-neutro';
  }

  if (formManifestacao) {
    const tipo = document.getElementById('tipo');
    const assunto = document.getElementById('assunto');
    const descricao = document.getElementById('descricao');
    const email = document.getElementById('email');
    const perfil = document.getElementById('perfil');
    const setor = document.getElementById('setor_relacionado');
    const contadorDescricao = document.getElementById('contadorDescricao');
    const contadorWrap = document.getElementById('contadorWrapDescricao');

    function validarTipo() {
      if (!tipo.value.trim()) { setErro(tipo, 'Selecione o tipo da manifestação.'); return false; }
      setCampoOk(tipo); return true;
    }
    function validarAssunto() {
      const valor = assunto.value.trim();
      if (!valor) { setErro(assunto, 'Informe o assunto.'); return false; }
      if (valor.length < 5) { setErro(assunto, 'Assunto muito curto. Mínimo de 5 caracteres.'); return false; }
      setCampoOk(assunto); return true;
    }
    function validarDescricao() {
      const valor = descricao.value.trim();
      const tamanho = valor.length;
      if (contadorDescricao) contadorDescricao.textContent = tamanho;
      contadorWrap?.classList.remove('invalido', 'valido');

      if (!valor) {
        setErro(descricao, 'Descreva sua manifestação.');
        contadorWrap?.classList.add('invalido');
        return false;
      }
      if (tamanho < 15) {
        setErro(descricao, 'Descreva melhor sua manifestação. Mínimo de 15 caracteres.');
        contadorWrap?.classList.add('invalido');
        return false;
      }
      setCampoOk(descricao);
      contadorWrap?.classList.add('valido');
      return true;
    }
    function validarEmailManifestacao() {
      if (!email.value.trim()) {
        limparErro(email);
        return true;
      }
      if (!validarEmail(email.value)) { setErro(email, 'Informe um e-mail válido.'); return false; }
      setCampoOk(email); return true;
    }

    [[tipo, validarTipo],[assunto, validarAssunto],[descricao, validarDescricao],[email, validarEmailManifestacao]].forEach(([campo, fn]) => bindValidation(campo, fn));
    // Não valida na carga — só atualiza o contador sem marcar erro
    if (contadorDescricao && descricao) contadorDescricao.textContent = descricao.value.trim().length;

    function preencherConfirmacao() {
      document.getElementById('confTipo').textContent = tipo.options[tipo.selectedIndex]?.text || 'Não informado';
      document.getElementById('confNome').textContent = valorOuPadrao(document.getElementById('nome').value, 'Anônimo');
      document.getElementById('confPerfil').textContent = valorOuPadrao(perfil.options[perfil.selectedIndex]?.text, 'Não informado');
      document.getElementById('confEmail').textContent = valorOuPadrao(email.value);
      const turmaValor = valorOuPadrao(document.getElementById('turma_setor').value);
      const confTurma = document.getElementById('confTurmaSetor');
      confTurma.textContent = turmaValor;
      confTurma.className = 'badge-curso-preview ' + classeCurso(turmaValor);
      document.getElementById('confSetorRelacionado').textContent = valorOuPadrao(setor.options[setor.selectedIndex]?.text);
      document.getElementById('confAssunto').textContent = valorOuPadrao(assunto.value);
      document.getElementById('confDescricao').textContent = valorOuPadrao(descricao.value);
    }

    function abrirModalConfirmacao() {
      preencherConfirmacao();
      modalConfirmacao.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }

    function fecharModalConfirmacao() {
      if (!modalConfirmacao) return;
      modalConfirmacao.style.display = 'none';
      document.body.style.overflow = '';
    }

    btnAbrirConfirmacao?.addEventListener('click', function () {
      const ok = [validarTipo(), validarAssunto(), validarDescricao(), validarEmailManifestacao()].every(Boolean);
      if (!ok) {
        formManifestacao.querySelector('.campo-erro')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      abrirModalConfirmacao();
    });

    btnFecharModal?.addEventListener('click', fecharModalConfirmacao);
    btnVoltarEdicao?.addEventListener('click', fecharModalConfirmacao);
    modalConfirmacao?.addEventListener('click', function (e) {
      if (e.target === modalConfirmacao) fecharModalConfirmacao();
    });
    btnConfirmarEnvio?.addEventListener('click', function () {
      formManifestacao.submit();
    });
  }
});

// ─── MÁSCARA DE TELEFONE ──────────────────────────────────────────────────
(function () {
  function mascaraTelefone(el) {
    el.addEventListener('input', function () {
      let v = this.value.replace(/\D/g, '').substring(0, 11);
      if (v.length > 10) {
        v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
      } else if (v.length > 6) {
        v = v.replace(/^(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
      } else if (v.length > 2) {
        v = v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
      } else if (v.length > 0) {
        v = v.replace(/^(\d*)/, '($1');
      }
      this.value = v;
    });
    // Bloqueia teclas não numéricas
    el.addEventListener('keydown', function (e) {
      const allow = ['Backspace','Delete','ArrowLeft','ArrowRight','Tab','Home','End'];
      if (!allow.includes(e.key) && !/^\d$/.test(e.key)) e.preventDefault();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[type="tel"], #mcTelefone').forEach(mascaraTelefone);
  });
})();

// ─── AUTO-DISMISS FLASH MESSAGES ─────────────────────────────────────────
// Regras:
//  • Mobile (pointer:coarse): 8 s mínimo (usuário lê mais devagar sem hover)
//  • Desktop: 5 s, pausado enquanto o mouse estiver sobre a mensagem
//  • Botão ✕ para fechar manualmente em qualquer dispositivo
document.addEventListener('DOMContentLoaded', function () {
  const flash = document.querySelector('.flash-msg');
  if (!flash) return;

  // Injeta botão de fechar se ainda não existir
  if (!flash.querySelector('.flash-close')) {
    const btn = document.createElement('button');
    btn.className = 'flash-close';
    btn.setAttribute('aria-label', 'Fechar mensagem');
    btn.innerHTML = '&times;';
    btn.style.cssText = [
      'background:none;border:none;cursor:pointer;',
      'font-size:1.25rem;line-height:1;padding:0 0 0 12px;',
      'opacity:.6;color:inherit;flex-shrink:0;',
    ].join('');
    flash.style.display = flash.style.display || 'flex';
    flash.style.alignItems = 'center';
    flash.style.justifyContent = 'space-between';
    flash.appendChild(btn);
  }

  const isMobile = window.matchMedia('(pointer:coarse)').matches;
  const DELAY = isMobile ? 8000 : 5000;

  function dispensar() {
    flash.style.transition = 'opacity .4s, transform .4s';
    flash.style.opacity = '0';
    flash.style.transform = 'translateY(-8px)';
    setTimeout(function () {
      flash.parentElement && flash.parentElement.remove();
    }, 400);
  }

  let timer = setTimeout(dispensar, DELAY);

  // Pausar no hover (só desktop — em mobile não há hover real)
  if (!isMobile) {
    flash.addEventListener('mouseenter', function () { clearTimeout(timer); });
    flash.addEventListener('mouseleave', function () { timer = setTimeout(dispensar, 2000); });
  }

  // Fechar manualmente
  const closeBtn = flash.querySelector('.flash-close');
  if (closeBtn) closeBtn.addEventListener('click', dispensar);
});

// ═══════════════════════════════════════════════════════════════════════════
// MÓDULO: Upload com preview de arquivos
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const inputArquivos = document.getElementById('arquivos');
    const previewWrap   = document.getElementById('arquivosPreview');
    if (!inputArquivos || !previewWrap) return;

    inputArquivos.addEventListener('change', function () {
      previewWrap.innerHTML = '';
      const arquivos = Array.from(this.files);

      arquivos.forEach(function (file) {
        const item = document.createElement('div');
        item.style.cssText = [
          'display:flex;align-items:center;gap:8px;',
          'background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;',
          'padding:6px 12px;font-size:.82rem;color:#166534;max-width:220px;',
        ].join('');

        const isImagem = file.type.startsWith('image/');

        if (isImagem) {
          const reader = new FileReader();
          reader.onload = function (e) {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.style.cssText = 'width:36px;height:36px;object-fit:cover;border-radius:6px;flex-shrink:0;';
            item.insertBefore(img, item.firstChild);
          };
          reader.readAsDataURL(file);
        } else {
          const icon = document.createElement('i');
          const iconClass = file.type === 'application/pdf' ? 'fa-file-pdf' : 'fa-file';
          icon.className = 'fa-solid ' + iconClass;
          icon.style.cssText = 'font-size:1.2rem;flex-shrink:0;';
          item.appendChild(icon);
        }

        const info = document.createElement('div');
        info.style.cssText = 'overflow:hidden;';

        const nome = document.createElement('div');
        nome.style.cssText = 'font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px;';
        nome.textContent = file.name;

        const tamanho = document.createElement('div');
        tamanho.style.cssText = 'color:#4ade80;font-size:.75rem;';
        tamanho.textContent = file.size > 1024 * 1024
          ? (file.size / 1024 / 1024).toFixed(1) + ' MB'
          : (file.size / 1024).toFixed(0) + ' KB';

        info.appendChild(nome);
        info.appendChild(tamanho);
        item.appendChild(info);
        previewWrap.appendChild(item);
      });

      if (arquivos.length > 0) {
        const total = document.createElement('div');
        total.style.cssText = 'font-size:.8rem;color:#6b7280;align-self:center;white-space:nowrap;';
        total.textContent = arquivos.length + ' arquivo(s) selecionado(s)';
        previewWrap.appendChild(total);
      }
    });
  });
})();

// ═══════════════════════════════════════════════════════════════════════════
// MÓDULO: Polling do chat + status ao vivo (acompanhar.php)
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const chatWrap   = document.getElementById('chatRespostas');
    const statusBadge = document.getElementById('statusAtualBadge');
    if (!chatWrap && !statusBadge) return;

    // Ler protocolo e último ID das mensagens já renderizadas no HTML
    const protocolo = (document.getElementById('protocoloAtual') || {}).value || '';
    if (!protocolo) return;

    let ultimoId = 0;
    const msgs = chatWrap ? chatWrap.querySelectorAll('[data-msg-id]') : [];
    msgs.forEach(function (el) {
      const id = parseInt(el.dataset.msgId, 10);
      if (id > ultimoId) ultimoId = id;
    });

    // Mapa de classes CSS por status
    const STATUS_CLASSES = {
      'Recebida':     'badge-status-recebida',
      'Em andamento': 'badge-status-andamento',
      'Resolvida':    'badge-status-resolvida',
      'Arquivada':    'badge-status-arquivada',
    };

    function renderMensagem(msg) {
      const div = document.createElement('div');
      div.dataset.msgId = msg.IDresposta;
      div.className = 'chat-bubble-acomp ' + (msg.autor_tipo === 'adm' ? 'chat-acomp-adm' : 'chat-acomp-usu');
      div.style.animation = 'chatEntrar .3s ease';
      div.innerHTML =
        '<div class="chat-autor-acomp">' +
          escapeHtml(msg.autor_nome) + ' · ' + msg.data_fmt +
        '</div>' +
        '<div>' + escapeHtml(msg.mensagem).replace(/\n/g, '<br>') + '</div>';
      return div;
    }

    function escapeHtml(str) {
      return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function poll() {
      const base = window._baseUrl || '/projeto_final/';
      const url  = base + 'app/api/chat_poll.php?protocolo='
                 + encodeURIComponent(protocolo)
                 + '&desde_id=' + ultimoId;

      fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) return;

          // Atualizar badge de status
          if (statusBadge && data.status) {
            const novaClasse = STATUS_CLASSES[data.status] || '';
            statusBadge.textContent = data.status;
            statusBadge.className = 'badge-status ' + novaClasse;
          }

          // Inserir mensagens novas no chat
          if (data.mensagens && data.mensagens.length > 0 && chatWrap) {
            data.mensagens.forEach(function (msg) {
              chatWrap.appendChild(renderMensagem(msg));
            });
            chatWrap.scrollTop = chatWrap.scrollHeight;

            ultimoId = data.ultimo_id;

            // Notificação push do browser para respostas do admin
            const temRespostaAdm = data.mensagens.some(function (m) {
              return m.autor_tipo === 'adm';
            });
            if (temRespostaAdm) {
              notificarBrowser('O Grêmio respondeu sua manifestação!', 'Abra a página para ver a resposta.');
            }
          }
        })
        .catch(function () { /* silencia erros de rede */ });
    }

    // Iniciar polling a cada 10 segundos
    setInterval(poll, 10000);
  });
})();

// ═══════════════════════════════════════════════════════════════════════════
// MÓDULO: Notificações push do browser
// ═══════════════════════════════════════════════════════════════════════════
function notificarBrowser(titulo, corpo) {
  if (!('Notification' in window)) return;

  function disparar() {
    if (document.visibilityState === 'hidden') {
      new Notification(titulo, {
        body: corpo,
        icon: window._baseUrl ? window._baseUrl + 'assets/images/logo-escola.png' : '',
        badge: window._baseUrl ? window._baseUrl + 'assets/images/logo-escola.png' : '',
      });
    }
  }

  if (Notification.permission === 'granted') {
    disparar();
  } else if (Notification.permission !== 'denied') {
    Notification.requestPermission().then(function (perm) {
      if (perm === 'granted') disparar();
    });
  }
}

// Pedir permissão de notificação quando o usuário estiver na página de acompanhamento
document.addEventListener('DOMContentLoaded', function () {
  if (document.getElementById('chatRespostas') && 'Notification' in window) {
    if (Notification.permission === 'default') {
      // Espera 3s para não aparecer logo ao carregar
      setTimeout(function () {
        Notification.requestPermission();
      }, 3000);
    }
  }
});

// ═══════════════════════════════════════════════════════════════════════════
// MÓDULO: Dashboard — filtros sem reload (AJAX + Chart.js update)
// ═══════════════════════════════════════════════════════════════════════════
(function () {
  document.addEventListener('DOMContentLoaded', function () {
    const formDash = document.getElementById('formDashFiltro');
    if (!formDash) return;

    // Referências aos gráficos — preenchidas após o Chart.js inicializar
    // Os gráficos são registrados em window._dashCharts pelo dashboard.php
    function atualizarGrafico(chart, labels, data) {
      if (!chart) return;
      chart.data.labels = labels;
      chart.data.datasets[0].data = data;
      chart.update('active');
    }

    function atualizarResumoCard(seletor, valor) {
      const el = document.querySelector(seletor);
      if (el) {
        el.style.transition = 'opacity .2s';
        el.style.opacity = '0';
        setTimeout(function () {
          el.textContent = valor;
          el.style.opacity = '1';
        }, 200);
      }
    }

    formDash.addEventListener('submit', function (e) {
      e.preventDefault();

      const inicio = formDash.querySelector('[name="dash_inicio"]').value;
      const fim    = formDash.querySelector('[name="dash_fim"]').value;
      const base   = window._baseUrl || '/projeto_final/';
      const url    = base + 'app/api/dashboard_data.php?dash_inicio='
                   + encodeURIComponent(inicio) + '&dash_fim=' + encodeURIComponent(fim);

      // Indicador visual de carregamento
      const btnAplicar = formDash.querySelector('[type="submit"]');
      const textoOriginal = btnAplicar.innerHTML;
      btnAplicar.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Atualizando…';
      btnAplicar.disabled = true;

      fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) return;

          const charts = window._dashCharts || {};

          // Atualizar gráficos
          atualizarGrafico(charts.evolucao, d.evolucao.labels, d.evolucao.data);
          atualizarGrafico(charts.status,   d.status.labels,   d.status.data);
          atualizarGrafico(charts.tipos,    d.tipos.labels,    d.tipos.data);
          atualizarGrafico(charts.cursos,   d.cursos.labels,   d.cursos.data);
          atualizarGrafico(charts.anonimo,  d.anonimo.labels,  d.anonimo.data);

          // Atualizar cards de resumo
          atualizarResumoCard('[data-resumo="total"]',      d.resumo.total);
          atualizarResumoCard('[data-resumo="recebidas"]',  d.resumo.recebidas);
          atualizarResumoCard('[data-resumo="andamento"]',  d.resumo.andamento);
          atualizarResumoCard('[data-resumo="resolvidas"]', d.resumo.resolvidas);
          atualizarResumoCard('[data-resumo="hoje"]',       d.resumo.hoje);
          atualizarResumoCard('[data-resumo="semana"]',     d.resumo.semana);
          atualizarResumoCard('[data-resumo="taxa"]',       d.resumo.taxa);

          // Atualizar tag de período no filtro
          let tagPeriodo = document.getElementById('tagPeriodo');
          if (!tagPeriodo) {
            tagPeriodo = document.createElement('span');
            tagPeriodo.id = 'tagPeriodo';
            tagPeriodo.style.cssText = 'background:var(--verde-xs);color:var(--verde-d);font-size:.78rem;font-weight:700;padding:6px 14px;border-radius:999px;border:1px solid rgba(26,107,64,0.2);';
            formDash.appendChild(tagPeriodo);
          }
          const fmtData = function (s) {
            if (!s) return '…';
            const p = s.split('-');
            return p[2] + '/' + p[1] + '/' + p[0];
          };
          tagPeriodo.innerHTML = '<i class="fa-solid fa-calendar-check me-1"></i>' +
            fmtData(inicio) + ' → ' + fmtData(fim);

        })
        .catch(function () {
          alert('Erro ao buscar dados. Verifique sua conexão.');
        })
        .finally(function () {
          btnAplicar.innerHTML = textoOriginal;
          btnAplicar.disabled  = false;
        });
    });

    // Botão Limpar — reseta gráficos sem reload recarregando com filtro vazio
    const btnLimpar = formDash.querySelector('.btn-limpar-dash');
    if (btnLimpar) {
      btnLimpar.addEventListener('click', function (e) {
        e.preventDefault();
        formDash.querySelector('[name="dash_inicio"]').value = '';
        formDash.querySelector('[name="dash_fim"]').value    = '';
        formDash.dispatchEvent(new Event('submit'));
        const tag = document.getElementById('tagPeriodo');
        if (tag) tag.remove();
      });
    }
  });
})();
