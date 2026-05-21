-- =============================================================================
-- IMPORTAÇÃO COMPLETA — Ouvidoria do Grêmio Escolar v2.0.1
-- EEEP Dom Walfrido Teixeira Vieira
--
-- HOW TO USE (phpMyAdmin):
--   1. Acesse phpMyAdmin → aba "Importar"
--   2. Selecione este arquivo e clique em "Executar"
--   3. Tudo será criado/atualizado automaticamente na ordem correta
--
-- HOW TO USE (linha de comando):
--   mysql -u root -p < database/import_all.sql
--
-- ⚠️  Este script inclui os dados de seed de desenvolvimento (admin + aluno teste).
--     NUNCA execute em produção — use apenas schema.sql em produção.
-- =============================================================================

-- ─────────────────────────────────────────────────────────────────────────────
-- 1. SCHEMA — cria o banco e todas as tabelas do zero
-- ─────────────────────────────────────────────────────────────────────────────

CREATE DATABASE IF NOT EXISTS `dbouvidoria` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `dbouvidoria`;

-- Remove tabelas na ordem correta (filhas antes das mães)
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `rate_limit`;
DROP TABLE IF EXISTS `remember_tokens`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `historico_status`;
DROP TABLE IF EXISTS `notificacoes`;
DROP TABLE IF EXISTS `respostas_manifest`;
DROP TABLE IF EXISTS `arquivos_manifest`;
DROP TABLE IF EXISTS `tbmanifest`;
DROP TABLE IF EXISTS `tipos`;
DROP TABLE IF EXISTS `tbusuarios`;
DROP TABLE IF EXISTS `tbadm`;
SET FOREIGN_KEY_CHECKS = 1;

-- Tabela de administradores
CREATE TABLE `tbadm` (
  `IDadm` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`  VARCHAR(80)  NOT NULL,
  `email` VARCHAR(180) NOT NULL UNIQUE,
  `senha` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`IDadm`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de usuários (alunos, responsáveis, etc.)
CREATE TABLE `tbusuarios` (
  `IDusu`       INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `nome`        VARCHAR(80)   NOT NULL,
  `cpf`         VARCHAR(14)   NOT NULL,
  `perfil`      VARCHAR(40)   DEFAULT NULL,
  `serie`       VARCHAR(20)   DEFAULT NULL,
  `curso`       VARCHAR(60)   DEFAULT NULL,
  `matricula`   VARCHAR(40)   DEFAULT NULL,
  `email`       VARCHAR(180)  NOT NULL UNIQUE,
  `telefone`    VARCHAR(20)   DEFAULT NULL,
  `senha`       VARCHAR(255)  NOT NULL,
  `foto_perfil` VARCHAR(255)  DEFAULT NULL,
  PRIMARY KEY (`IDusu`),
  UNIQUE KEY `uk_tbusuarios_cpf` (`cpf`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tipos de manifestação
CREATE TABLE `tipos` (
  `IDtipo`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `descricao` VARCHAR(50)  NOT NULL,
  PRIMARY KEY (`IDtipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Manifestações
CREATE TABLE `tbmanifest` (
  `IDmanifest`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `IDusu`               INT UNSIGNED DEFAULT NULL,
  `IDadm`               INT UNSIGNED DEFAULT NULL,
  `IDtipo`              INT UNSIGNED NOT NULL,
  `protocolo`           VARCHAR(30)  NOT NULL UNIQUE,
  `assunto`             VARCHAR(180) NOT NULL,
  `manifest`            TEXT         NOT NULL,
  `status`              VARCHAR(20)  NOT NULL DEFAULT 'Recebida',
  `feedback`            TEXT         DEFAULT NULL,
  `contato`             VARCHAR(100) DEFAULT NULL,
  `nome_manifestante`   VARCHAR(80)  NOT NULL,
  `perfil_manifestante` VARCHAR(40)  NOT NULL,
  `turma_setor`         VARCHAR(80)  DEFAULT NULL,
  `setor_relacionado`   VARCHAR(80)  DEFAULT NULL,
  `data_ocorrencia`     DATE         DEFAULT NULL,
  `util`                TINYINT(1)   DEFAULT NULL,
  `nota_satisfacao`     TINYINT(1)   DEFAULT NULL,
  `comentario_satisfacao` TEXT       DEFAULT NULL,
  `arquivada`           TINYINT(1)   NOT NULL DEFAULT 0,
  `criado_em`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`IDmanifest`),
  KEY `fk_manifest_usuario`    (`IDusu`),
  KEY `fk_manifest_adm`        (`IDadm`),
  KEY `fk_manifest_tipo`       (`IDtipo`),
  KEY `idx_manifest_status`    (`status`),
  KEY `idx_manifest_criado`    (`criado_em`),
  KEY `idx_manifest_arquivada` (`arquivada`),
  CONSTRAINT `fk_manifest_usuario` FOREIGN KEY (`IDusu`)   REFERENCES `tbusuarios` (`IDusu`) ON DELETE SET NULL,
  CONSTRAINT `fk_manifest_adm`     FOREIGN KEY (`IDadm`)   REFERENCES `tbadm`       (`IDadm`) ON DELETE SET NULL,
  CONSTRAINT `fk_manifest_tipo`    FOREIGN KEY (`IDtipo`)  REFERENCES `tipos`        (`IDtipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Arquivos anexos das manifestações
CREATE TABLE `arquivos_manifest` (
  `IDarquivo`    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `IDmanifest`   INT UNSIGNED  NOT NULL,
  `nome_original` VARCHAR(255) NOT NULL,
  `nome_arquivo`  VARCHAR(255) NOT NULL,
  `tamanho`       INT UNSIGNED NOT NULL,
  `mime_type`     VARCHAR(100) NOT NULL,
  `criado_em`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`IDarquivo`),
  KEY `fk_arquivo_manifest` (`IDmanifest`),
  CONSTRAINT `fk_arquivo_manifest` FOREIGN KEY (`IDmanifest`) REFERENCES `tbmanifest` (`IDmanifest`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Respostas / chat interno
CREATE TABLE `respostas_manifest` (
  `IDresposta`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `IDmanifest`      INT UNSIGNED NOT NULL,
  `IDadm`           INT UNSIGNED DEFAULT NULL,
  `IDusu`           INT UNSIGNED DEFAULT NULL,
  `mensagem`        TEXT         NOT NULL,
  `autor_nome`      VARCHAR(80)  NOT NULL,
  `autor_tipo`      ENUM('adm','usuario') NOT NULL,
  `lida_pelo_usuario` TINYINT(1) NOT NULL DEFAULT 0,
  `lida_pelo_adm`   TINYINT(1)  NOT NULL DEFAULT 0,
  `criado_em`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`IDresposta`),
  KEY `fk_resposta_manifest` (`IDmanifest`),
  KEY `fk_resposta_adm`      (`IDadm`),
  KEY `fk_resposta_usu`      (`IDusu`),
  CONSTRAINT `fk_resposta_manifest` FOREIGN KEY (`IDmanifest`) REFERENCES `tbmanifest`  (`IDmanifest`) ON DELETE CASCADE,
  CONSTRAINT `fk_resposta_adm`      FOREIGN KEY (`IDadm`)      REFERENCES `tbadm`        (`IDadm`)      ON DELETE SET NULL,
  CONSTRAINT `fk_resposta_usu`      FOREIGN KEY (`IDusu`)      REFERENCES `tbusuarios`   (`IDusu`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Notificações internas
CREATE TABLE `notificacoes` (
  `IDnotif`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `IDusu`     INT UNSIGNED DEFAULT NULL,
  `IDadm`     INT UNSIGNED DEFAULT NULL,
  `tipo`      VARCHAR(40)  NOT NULL,
  `titulo`    VARCHAR(120) NOT NULL,
  `mensagem`  TEXT         NOT NULL,
  `link`      VARCHAR(255) DEFAULT NULL,
  `lida`      TINYINT(1)   NOT NULL DEFAULT 0,
  `criado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`IDnotif`),
  KEY `fk_notif_usuario` (`IDusu`),
  KEY `fk_notif_adm`     (`IDadm`),
  CONSTRAINT `fk_notif_usuario` FOREIGN KEY (`IDusu`) REFERENCES `tbusuarios` (`IDusu`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_adm`     FOREIGN KEY (`IDadm`) REFERENCES `tbadm`       (`IDadm`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Histórico de mudanças de status
CREATE TABLE `historico_status` (
  `IDhistorico`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `IDmanifest`     INT UNSIGNED NOT NULL,
  `IDadm`          INT UNSIGNED DEFAULT NULL,
  `status_anterior` VARCHAR(20) NOT NULL,
  `status_novo`     VARCHAR(20) NOT NULL,
  `observacao`      TEXT        DEFAULT NULL,
  `criado_em`       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`IDhistorico`),
  KEY `fk_hist_manifest` (`IDmanifest`),
  KEY `fk_hist_adm`      (`IDadm`),
  CONSTRAINT `fk_hist_manifest` FOREIGN KEY (`IDmanifest`) REFERENCES `tbmanifest` (`IDmanifest`) ON DELETE CASCADE,
  CONSTRAINT `fk_hist_adm`      FOREIGN KEY (`IDadm`)      REFERENCES `tbadm`       (`IDadm`)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens de recuperação de senha
CREATE TABLE `password_resets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(180) NOT NULL,
  `token`      VARCHAR(255) NOT NULL,
  `expires_at` DATETIME     NOT NULL,
  `used_at`    DATETIME     DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tokens do cookie "lembrar-me"
CREATE TABLE `remember_tokens` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` VARCHAR(64)  NOT NULL UNIQUE,
  `email`      VARCHAR(180) NOT NULL,
  `tipo`       VARCHAR(10)  NOT NULL DEFAULT 'usuario',
  `expires_at` DATETIME     NOT NULL,
  `criado_em`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_expires`    (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Tokens opacos para o cookie lembrar-me.';

-- Rate limiting de login e recuperação de senha
CREATE TABLE `rate_limit` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `chave`     VARCHAR(255) NOT NULL,
  `criado_em` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_chave`  (`chave`),
  KEY `idx_criado` (`criado_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Registra tentativas para rate limiting.';

-- ─────────────────────────────────────────────────────────────────────────────
-- 2. DADOS INICIAIS — tipos de manifestação (obrigatório em qualquer ambiente)
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `tipos` (`descricao`) VALUES
  ('Sugestão'),
  ('Elogio'),
  ('Reclamação'),
  ('Denúncia');

-- ─────────────────────────────────────────────────────────────────────────────
-- 3. SEED DE DESENVOLVIMENTO — admin + aluno de teste
--    Senha de ambos: 123mudar
--    ⚠️  REMOVA ou comente este bloco antes de usar em produção
-- ─────────────────────────────────────────────────────────────────────────────

INSERT INTO `tbadm` (`nome`, `email`, `senha`) VALUES
  ('Administrador do Grêmio', 'admin@gremio.com',
   '$2y$12$4hNLwCASD.kp80Nld97E2uJPxlIiSmaaZ8jrrINEyaa5Tsgd9XwDa');

INSERT INTO `tbusuarios` (`nome`, `cpf`, `perfil`, `email`, `telefone`, `senha`) VALUES
  ('Usuário Teste', '00000000000', 'Aluno(a)', 'aluno@gremio.com', '(88) 99999-9999',
   '$2y$12$4hNLwCASD.kp80Nld97E2uJPxlIiSmaaZ8jrrINEyaa5Tsgd9XwDa');

-- ─────────────────────────────────────────────────────────────────────────────
-- FIM — banco pronto para uso
-- Acesse: http://localhost/projeto_final
-- Admin:  admin@gremio.com  /  123mudar
-- Aluno:  aluno@gremio.com  /  123mudar
-- ⚠️  Troque as senhas padrão imediatamente após o primeiro acesso!
-- ─────────────────────────────────────────────────────────────────────────────
