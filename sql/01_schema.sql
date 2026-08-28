-- =====================================================================
--  Sistema de Licenciamento Total Scale - Schema do banco de dados
--  MySQL 5.7+ / MariaDB 10.2+
-- =====================================================================
--  Execute:  mysql -u root -p < 01_schema.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS licencas
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE licencas;

-- ---------------------------------------------------------------------
-- Usuarios do painel (voce hoje; revendedores/funcionarios no futuro)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(120)  NOT NULL,
  email          VARCHAR(160)  NOT NULL UNIQUE,
  senha_hash     VARCHAR(255)  NOT NULL,      -- password_hash() do PHP
  papel          ENUM('admin','revendedor') NOT NULL DEFAULT 'admin',
  ativo          TINYINT(1)    NOT NULL DEFAULT 1,
  criado_em      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Clientes finais (quem usa o Total Scale)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clientes (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  razao_social   VARCHAR(160)  NOT NULL,
  cnpj           VARCHAR(20)   NULL,
  contato        VARCHAR(120)  NULL,
  telefone       VARCHAR(40)   NULL,
  email          VARCHAR(160)  NULL,
  observacao     TEXT          NULL,
  criado_por     INT           NULL,          -- usuario que cadastrou
  criado_em      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cli_user FOREIGN KEY (criado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Licencas emitidas
--   Cada licenca pertence a um cliente e (apos ativada) a uma maquina.
--   O "fingerprint" so e preenchido quando a maquina ativa.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS licencas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id     INT           NOT NULL,
  chave          VARCHAR(35)   NOT NULL UNIQUE,   -- ex: TS6X-9K2M-... (o cliente digita)
  fingerprint    VARCHAR(80)   NULL,              -- maquina onde foi ativada
  modulos        VARCHAR(255)  NOT NULL DEFAULT 'TBE', -- CSV: TBE,RFID,LPR
  emitido_em     DATE          NOT NULL,
  expira_em      DATE          NOT NULL,          -- validade de uso
  status         ENUM('nova','ativa','revogada','expirada') NOT NULL DEFAULT 'nova',
  tipo_ativacao  ENUM('online','offline') NULL,   -- como foi ativada
  ativada_em     DATETIME      NULL,
  criado_por     INT           NULL,
  criado_em      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_lic_cli  FOREIGN KEY (cliente_id) REFERENCES clientes(id),
  CONSTRAINT fk_lic_user FOREIGN KEY (criado_por) REFERENCES usuarios(id),
  INDEX idx_lic_fp (fingerprint),
  INDEX idx_lic_status (status)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Log de ativacoes e verificacoes (auditoria)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ativacoes_log (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  licenca_id     INT           NULL,
  chave          VARCHAR(35)   NULL,
  fingerprint    VARCHAR(80)   NULL,
  ip             VARCHAR(45)   NULL,
  acao           VARCHAR(40)   NOT NULL,   -- ativar_online, gerar_offline, verificar, revogar...
  resultado      VARCHAR(20)   NOT NULL,   -- ok, negado, erro
  detalhe        VARCHAR(255)  NULL,
  criado_em      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_log_lic (licenca_id),
  INDEX idx_log_data (criado_em)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- O usuario admin inicial NAO e criado aqui (para nao fixar um hash de
-- senha invalido). Rode, na VPS, apos importar este schema:
--
--     php setup/criar_admin.php  seu@email.com  SuaSenhaForte
--
-- Isso gera o hash correto com password_hash() e insere o admin.
-- ---------------------------------------------------------------------
