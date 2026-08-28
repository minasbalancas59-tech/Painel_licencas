-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 07: nome fantasia e multiplos contatos por cliente
--
--  Aplicar depois da 06:
--      mysql licencas < sql/07_contatos.sql
--
--  Aditiva e idempotente. Os contatos que ja existem nas colunas
--  contato/telefone/email sao COPIADOS para a tabela nova como contato
--  principal - nenhum dado se perde.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _add_col7;
DELIMITER //
CREATE PROCEDURE _add_col7(
    IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

-- ---------------------------------------------------------------------
-- 1) NOME FANTASIA
--    O cliente costuma se identificar pelo fantasia, nao pela razao
--    social ("Mineracao Sao Jose" vs "MSJ Participacoes Ltda").
-- ---------------------------------------------------------------------
CALL _add_col7('clientes','nome_fantasia',
    'nome_fantasia VARCHAR(160) NULL AFTER razao_social');

-- dados complementares vindos da consulta por CNPJ
CALL _add_col7('clientes','municipio',
    'municipio VARCHAR(120) NULL AFTER email');
CALL _add_col7('clientes','uf',
    'uf CHAR(2) NULL AFTER municipio');

-- ---------------------------------------------------------------------
-- 2) CONTATOS
--    Uma empresa tem o comprador, o operador da balanca, o TI, o
--    financeiro. Uma linha so nao da conta.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cliente_contatos (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT          NOT NULL,
    nome       VARCHAR(120) NOT NULL,
    cargo      VARCHAR(80)  NULL,
    telefone   VARCHAR(40)  NULL,
    email      VARCHAR(160) NULL,
    observacao VARCHAR(255) NULL,
    principal  TINYINT(1)   NOT NULL DEFAULT 0,
    criado_em  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contato_cli FOREIGN KEY (cliente_id)
        REFERENCES clientes(id) ON DELETE CASCADE,
    INDEX idx_contato_cli (cliente_id),
    INDEX idx_contato_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) MIGRA os contatos existentes para a tabela nova
--    So roda uma vez: o NOT EXISTS impede duplicar se a migracao for
--    reaplicada.
-- ---------------------------------------------------------------------
INSERT INTO cliente_contatos (cliente_id, nome, telefone, email, principal)
SELECT c.id,
       COALESCE(NULLIF(TRIM(c.contato),''), 'Contato principal'),
       NULLIF(TRIM(c.telefone),''),
       NULLIF(TRIM(c.email),''),
       1
  FROM clientes c
 WHERE (COALESCE(TRIM(c.contato),'')  <> ''
     OR COALESCE(TRIM(c.telefone),'') <> ''
     OR COALESCE(TRIM(c.email),'')    <> '')
   AND NOT EXISTS (
        SELECT 1 FROM cliente_contatos cc WHERE cc.cliente_id = c.id
   );

DROP PROCEDURE _add_col7;

-- =====================================================================
--  NOTA: as colunas contato/telefone/email de `clientes` continuam
--  existindo, mas o painel passa a ler da tabela cliente_contatos.
--  Ficam por seguranca ate a proxima versao - nao remova agora.
-- =====================================================================

-- =====================================================================
--  CONFERENCIA:
--  SHOW COLUMNS FROM clientes LIKE 'nome_fantasia';
--  SHOW TABLES LIKE 'cliente_contatos';
--  SELECT COUNT(*) FROM cliente_contatos;
-- =====================================================================
