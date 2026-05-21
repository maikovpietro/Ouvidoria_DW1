# Ouvidoria do Grêmio Escolar
### EEEP Dom Walfrido Teixeira Vieira · Sobral, Ceará

> Sistema web de ouvidoria escolar desenvolvido para o Grêmio Estudantil da EEEP Dom Walfrido Teixeira Vieira. Permite que alunos, responsáveis, professores e servidores registrem manifestações de forma anônima ou identificada, com acompanhamento em tempo real por protocolo.

**Versão:** `2.0.1`  
**Stack:** PHP 8.2 · MySQL · Bootstrap 5 · Chart.js · PHPMailer  
**Ambiente:** Apache (XAMPP/WAMP) · `localhost`

---

## Funcionalidades

### Para o público
- Envio de manifestações em 4 categorias: **Sugestão, Elogio, Reclamação, Denúncia**
- Modo **anônimo** ou **identificado**, com escolha explícita antes do envio
- Revisão dos dados antes do envio final (modal de confirmação)
- Anexo de arquivos (imagens, PDFs, documentos — máx. 10 MB cada)
- Acompanhamento por **protocolo** (`GRE-AAAAMMDD-XXXXXX`) com timeline de status
- Validação de protocolo em tempo real (AJAX)
- **Conversa direta** com o Grêmio pela página de acompanhamento
- **Avaliação de satisfação** com estrelas (1–5) e comentário opcional
- E-mail de confirmação automático com o protocolo ao enviar
- E-mail automático ao aluno quando o status é atualizado

### Para o usuário cadastrado
- Cadastro e login com CPF e senha
- Painel `Minha Conta` com histórico de manifestações
- Foto de perfil com upload direto
- Notificações em tempo real (sino) para respostas e atualizações
- Recuperação de senha por e-mail com token seguro

### Para o administrador (Grêmio)
- Página inicial exclusiva com estatísticas em tempo real e acesso rápido ao painel
- Painel administrativo com visão geral de todas as manifestações
- Filtros por tipo, status, período (data início/fim) e busca por texto
- **Paginação** (10 por página) para grandes volumes
- **Exportar CSV** com todas as manifestações (compatível com Excel)
- **Arquivar e excluir** manifestações individualmente
- Resposta direta ao aluno pelo painel (chat interno)
- Atualização de status com feedback e histórico completo de movimentações
- Notificação interna a **todos os admins** ao receber resposta de aluno
- Dashboard com gráficos em tempo real:
  - Evolução de manifestações nos últimos 30 dias (gráfico de linha)
  - Distribuição por status (donut)
  - Distribuição por tipo (barras)
  - Distribuição por curso (barras horizontais)
  - Anônimas vs. identificadas (donut)
  - Filtro de período aplicável a todos os gráficos

---

## Instalação

### Pré-requisitos
- PHP 8.1 ou superior
- MySQL 5.7 ou superior
- Apache com `mod_rewrite`
- XAMPP, WAMP ou equivalente

### Passo a passo

**1. Copie a pasta para o servidor**
```
htdocs/
```

**2. Configure as variáveis de ambiente**

Copie `.env.example` para `.env` e preencha com seus dados:
```bash
cp .env.example .env
```

```ini
# Banco de dados
DB_HOST=localhost
DB_NAME=dbouvidoria
DB_USER=root
DB_PASS=

# URL — altere se mudar o nome da pasta
APP_URL=http://localhost/nome da pasta

# SMTP (Gmail, por exemplo)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=seu@email.com
MAIL_PASSWORD=sua_senha_de_app
MAIL_FROM_ADDRESS=seu@email.com
MAIL_FROM_NAME=Ouvidoria do Grêmio Escolar - EEEP Dom Walfrido
```

> ⚠️ **Nunca commite o arquivo `.env`** — ele já está listado no `.gitignore`.

**3. Importe o banco de dados**

**Use apenas o `import_all.sql`** — ele já contém tudo: estrutura, índices e dados de teste.

```
database/import_all.sql
```

Abra o phpMyAdmin → aba **Importar** → selecione o arquivo → clique em **Executar**. Pronto.

> Os outros arquivos da pasta `database/` são opcionais e para situações específicas — veja a tabela abaixo.

#### Guia dos arquivos SQL

| Arquivo | Quando usar |
|---------|-------------|
| ✅ **`import_all.sql`** | **Instalação nova** — use apenas este |
| `schema.sql` | Instalação em produção (sem dados de teste) |
| `migrate_indexes.sql` | Banco já existente de versão anterior — adiciona índices sem recriar |
| `fix_notificacoes.sql` | Banco com notificações gravadas por versão muito antiga |
| `seed_dev.sql` | Já está incluso no `import_all.sql` — não precisa rodar separado |

**4. Acesse no navegador**
```
http://localhost/projeto_final
```

### Credenciais padrão (seed de desenvolvimento)

| Perfil | E-mail | Senha |
|--------|--------|-------|
| Administrador | `admin@gremio.com` | `admin123` |
| Aluno teste | `aluno@gremio.com` | `123mudar` |

> ⚠️ Troque as senhas padrão imediatamente após a primeira instalação.

---

## Estrutura do projeto

```
projeto_final/
│
├── .env                           ← 🔒 Credenciais reais (não commitar)
├── .env.example                   ← Modelo público para o .env
├── .gitignore
├── index.php                      ← Página inicial (bifurcada: admin / público)
│
├── app/
│   ├── manifestacao.php           ← Envio de manifestação (bloqueado para admin)
│   ├── acompanhar.php             ← Consulta por protocolo
│   ├── notificacoes.php           ← Central de notificações
│   ├── sobre.php                  ← Sobre a escola
│   │
│   ├── auth/
│   │   ├── login.php              ← Login e cadastro (com validação frontend)
│   │   ├── logout.php
│   │   ├── forgot_password.php
│   │   ├── reset_password.php
│   │   └── email_enviado.php
│   │
│   └── painel/
│       ├── adm.php                ← Painel do Grêmio (admin)
│       ├── dashboard.php          ← Dashboard com gráficos
│       └── minha_conta.php        ← Painel do aluno
│
├── assets/
│   ├── css/style.css
│   ├── js/app.js
│   └── images/
│
├── config/
│   ├── bootstrap.php              ← Include único para todas as páginas
│   ├── config.php                 ← Lê variáveis do .env (sem credenciais hardcoded)
│   ├── paths.php                  ← Constantes de caminho
│   └── functions.php              ← Funções globais
│
├── includes/
│   ├── header.php
│   └── footer.php
│
├── lib/                           ← PHPMailer
├── storage/
│   ├── fotos/                     ← Fotos de perfil
│   └── manifestacoes/             ← Anexos das manifestações
│
└── database/
    ├── import_all.sql             ← ✅ USE ESTE — importação completa (schema + seed)
    ├── schema.sql                 ← Apenas estrutura, sem dados de seed (produção)
    ├── migrate_indexes.sql        ← Bancos existentes: adiciona índices de performance
    ├── fix_notificacoes.sql       ← Corrige links de notificações de versões antigas
    └── seed_dev.sql               ← Dados de teste isolados (já inclusos no import_all)
```

---

## Boas práticas do código

Cada página começa com um único `require_once` do bootstrap:

```php
// Da raiz (index.php)
require_once __DIR__ . '/config/bootstrap.php';

// De app/
require_once __DIR__ . '/../config/bootstrap.php';

// De app/auth/ ou app/painel/
require_once __DIR__ . '/../../config/bootstrap.php';
```

Use sempre as constantes de caminho — nunca strings hardcoded:

```php
// ✅ Correto
$dir = STORAGE_MANIFESTACOES;
require_once LIB_PATH . '/src/PHPMailer.php';

// ❌ Evitar
$dir = __DIR__ . '/../../storage/manifestacoes/';
```

Para links e redirects, use sempre `BASE_URL` ou `$_base`:

```php
// Redirect PHP
header('Location: ' . BASE_URL . 'app/acompanhar.php?protocolo=' . $protocolo);

// Link HTML (via header.php — $_base já está disponível)
<a href="<?= $_base ?>app/manifestacao.php">Fazer Manifestação</a>
```

Todo formulário `method="post"` deve incluir o token CSRF:

```php
<form method="post">
  <?= csrfInput() ?>
  <!-- campos do formulário -->
</form>
```

---

## Segurança

- **Credenciais via `.env`** — nenhuma senha ou chave hardcoded no código-fonte
- **Proteção CSRF** em todos os formulários POST (token por sessão)
- **Rate limiting** no login e na recuperação de senha (10 tentativas / 10 min)
- **Session fixation** prevenida com `session_regenerate_id(true)` após login
- **Cookies seguros** — `HttpOnly`, `SameSite=Strict`, `Secure` (em HTTPS)
- **Senhas** armazenadas com `password_hash()` (bcrypt) — sem fallback plaintext
- **Prepared statements** em todas as queries (PDO com `EMULATE_PREPARES = false`)
- **Upload** validado por MIME type real (`finfo`) + extensão + `getimagesize()` para imagens
- **Mensagens de erro genéricas** no login — não revelam se o e-mail existe
- **Token de reset** gerado com `random_bytes(32)`, armazenado como `sha256`, expira em 1 hora
- **Redefinição de senha** via transação — atualiza apenas a tabela correta (adm ou usuário)
- **Notificações** com allowlist para o nome da coluna (previne SQL injection futuro)
- **Exclusão de conta** exige confirmação de senha antes de executar o DELETE
- **`debug.php`** bloqueado em 3 camadas: guarda `DEV_MODE`, `.htaccess` e `.gitignore`
- Pasta `storage/` protegida por `.htaccess` (bloqueia execução de PHP)

---

## Changelog

### v2.0.1.1 — atual
**Banco de dados / Instalação**
- `database/import_all.sql` criado: arquivo único de importação que reúne schema completo + seed de desenvolvimento, na ordem correta com `FOREIGN_KEY_CHECKS` desativado para evitar erros de constraint — **agora basta importar um único arquivo**
- `database/migrate_indexes.sql` corrigido: `ADD KEY` simples substituído por `DROP KEY IF EXISTS` + `ADD KEY`, tornando o script idempotente (não falha se o índice já existir)

**Documentação**
- Guia completo dos arquivos SQL com tabela explicando quando usar cada um
- Credenciais padrão corrigidas no README (`123mudar`, não `123456`)
- Estrutura do projeto atualizada com todos os arquivos da pasta `database/`

---

### v2.0.1
**Segurança**
- Exclusão de conta agora exige confirmação de senha (verificação no backend com `senhaConfere()`)
- Chave do rate limiting truncada a 100 chars para evitar inserções gigantes no banco (`login:IP:email`)
- Idem em `forgot_password.php` (chave `reset:email`)
- Administrador redirecionado para o Painel do Grêmio ao tentar acessar `manifestacao.php` diretamente por URL

**Validação**
- Campo `nome` limitado a 80 caracteres: `maxlength="80"` no HTML + `mb_strlen` no backend (cadastro e edição de perfil)
- Telefone validado no backend com regex antes de persistir no banco
- Validação frontend completa no formulário de login e cadastro: o spinner/loading **só dispara após validar todos os campos** — anteriormente o botão travava mesmo com campos vazios

**Performance**
- Índices adicionados em `tbmanifest`: `idx_manifest_status`, `idx_manifest_criado`, `idx_manifest_arquivada`
- `database/migrate_indexes.sql` criado para aplicar os índices em bancos já existentes sem precisar recriar o schema

**UX / Interface**
- Página inicial completamente bifurcada: admin vê hero de gestão com estatísticas em tempo real e acesso rápido ao Painel do Grêmio; alunos veem a página pública original
- Menus superior e lateral: admin não vê mais "Fazer Manifestação", "Sobre a Escola" ou "Acompanhar"
- Badge "Novo" removido do botão "Fazer Manifestação"
- Opção "Comunidade" removida de todos os seletores de perfil (cadastro, edição de conta, formulário de manifestação) e da função `validarPerfil()` no backend
- Formulário de exclusão de conta exibe campo de senha com instrução clara

**Correções**
- `index.php`: nome de tabela corrigido de `tbmanifestacoes` para `tbmanifest` (causava erro fatal de SQL para o admin)

---

### v2.0.0
**Segurança**
- Credenciais movidas para `.env` (sem mais hardcode em `config.php`)
- Proteção CSRF implementada em todos os formulários POST
- Rate limiting no login (10 tentativas/10 min) e na recuperação de senha (3 envios/15 min)
- `session_regenerate_id(true)` após autenticação bem-sucedida (previne session fixation)
- Cookies com flags `HttpOnly`, `SameSite=Strict` e `Secure`
- `senhaConfere()` — removido fallback inseguro que comparava hash com texto puro
- Upload de arquivos valida MIME type pelo conteúdo real (`finfo`), não pelo nome
- Mensagens de erro no login agora são genéricas (não revelam e-mail válido ou não)
- Token de recuperação de senha: armazenado como `sha256`, expiração de 1 hora
- `atualizarSenhaPorReset()`: usa transação e atualiza apenas a tabela certa

**Lógica**
- Todo DDL (`ALTER TABLE`, `CREATE TABLE`) removido do runtime — schema centralizado em `database/schema.sql`
- Avaliação por estrelas em `acompanhar.php`: adicionado ownership check (usuário só avalia suas próprias manifestações)
- Notificação de resposta do aluno agora é enviada a **todos os admins** (antes só o primeiro)
- URL do link de recuperação de senha corrigida (antes apontava para 404)
- Redefinição de senha: atualiza apenas a tabela correta dentro de uma transação
- `$coluna` nas funções de notificação protegida por allowlist
- Todos os `catch` vazios substituídos por `error_log()`

**UX / Design**
- Spinner de carregamento em todos os botões de submit
- Mensagens de erro de autenticação unificadas e genéricas
- Tabela `rate_limit` incluída no `schema.sql`

---

### v1.5.0
- Arquivar e excluir manifestações no painel do admin
- Exportação de manifestações em CSV
- Filtro por período no painel e no dashboard
- Paginação no painel administrativo
- Avaliação de satisfação por estrelas (1–5)
- Correções de CSS/JS e responsividade mobile

### v1.0.0
- Lançamento inicial
- Formulário de manifestação (anônima/identificada)
- Acompanhamento por protocolo com timeline
- Painel administrativo com gestão de manifestações
- Sistema de chat entre aluno e Grêmio
- Notificações internas e por e-mail
- Dashboard com 5 gráficos Chart.js
- Autenticação com recuperação de senha
- Upload de arquivos e foto de perfil

---

## Licença

Projeto desenvolvido para uso interno da **EEEP Dom Walfrido Teixeira Vieira**.  
Distribuição e uso comercial não autorizados.

---

*Desenvolvido por Icaro Martins Azevedo · 2026*
