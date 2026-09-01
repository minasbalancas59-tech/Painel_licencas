-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 21: revendedor sem acesso ao painel e CNPJ unico
--
--  Aplicar depois da 20:
--      mysql licencas < sql/21_revendedor_sem_login.sql
--
--  DUAS CORREÇÕES
--
--  1. REVENDEDOR SEM LOGIN
--     Nem todo parceiro vai usar o painel. Ele precisa existir como
--     cadastro comercial - aparecendo nas licencas e no financeiro -
--     sem que voce tenha de inventar um e-mail e uma senha para ele.
--
--     email e senha_hash passam a aceitar NULL. Quem tem e-mail
--     continua com unicidade garantida; quem nao tem simplesmente nao
--     entra no painel.
--
--  2. CNPJ DUPLICADO
--     O UNIQUE estava no e-mail, entao dois cadastros com o mesmo CNPJ
--     e e-mails diferentes (".com" e ".com.br") passaram sem reclamar.
--     Agora o CNPJ tambem e unico - normalizado para so digitos, senao
--     "11.222.333/0001-81" e "11222333000181" seriam tratados como
--     empresas diferentes.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
--  1. login opcional
-- ---------------------------------------------------------------------
ALTER TABLE usuarios MODIFY email      VARCHAR(160) NULL;
ALTER TABLE usuarios MODIFY senha_hash VARCHAR(255) NULL;

-- e-mail em branco vira NULL: string vazia repetida quebraria o UNIQUE,
-- enquanto varios NULL convivem sem conflito
UPDATE usuarios SET email = NULL      WHERE email = '';
UPDATE usuarios SET senha_hash = NULL WHERE senha_hash = '';

-- ---------------------------------------------------------------------
--  2. CNPJ unico entre revendedores
--     Coluna gerada com so os digitos: e nela que o indice atua.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col21;
DELIMITER //
CREATE PROCEDURE _add_col21()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'cnpj_norm'
    ) THEN
        ALTER TABLE usuarios
          ADD COLUMN cnpj_norm VARCHAR(14)
              GENERATED ALWAYS AS (
                NULLIF(REGEXP_REPLACE(COALESCE(cnpj,''), '[^0-9]', ''), '')
              ) STORED AFTER cnpj;
    END IF;
END //
DELIMITER ;
CALL _add_col21();
DROP PROCEDURE _add_col21;

-- =====================================================================
--  ATENCAO: o indice abaixo FALHA se ainda houver CNPJ duplicado.
--  Rode antes para conferir:
--
--    SELECT cnpj_norm, COUNT(*) FROM usuarios
--     WHERE cnpj_norm IS NOT NULL GROUP BY cnpj_norm HAVING COUNT(*) > 1;
--
--  E resolva os duplicados (apague ou corrija) antes de continuar.
-- =====================================================================
DROP PROCEDURE IF EXISTS _add_idx21;
DELIMITER //
CREATE PROCEDURE _add_idx21()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'uq_usuario_cnpj'
    ) THEN
        ALTER TABLE usuarios ADD UNIQUE KEY uq_usuario_cnpj (cnpj_norm);
    END IF;
END //
DELIMITER ;
CALL _add_idx21();
DROP PROCEDURE _add_idx21;

-- ---------------------------------------------------------------------
--  3. mesma protecao para clientes
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col21b;
DELIMITER //
CREATE PROCEDURE _add_col21b()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'cnpj_norm'
    ) THEN
        ALTER TABLE clientes
          ADD COLUMN cnpj_norm VARCHAR(14)
              GENERATED ALWAYS AS (
                NULLIF(REGEXP_REPLACE(COALESCE(cnpj,''), '[^0-9]', ''), '')
              ) STORED AFTER cnpj;
        ALTER TABLE clientes ADD INDEX idx_cli_cnpj_norm (cnpj_norm);
    END IF;
END //
DELIMITER ;
CALL _add_col21b();
DROP PROCEDURE _add_col21b;

-- Em clientes o indice NAO e unico de proposito: filial e matriz tem
-- CNPJ diferente, mas ha casos legitimos de recadastro que voce vai
-- querer resolver olhando, nao por bloqueio do banco. O painel avisa
-- ao detectar repetido.

-- ---------------------------------------------------------------------
--  4. clientes tambem precisam ser desativaveis
--     Cliente que encerrou contrato nao deve sumir: as licencas dele
--     sao historico de venda. Desativar tira das listas de selecao sem
--     apagar nada.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS _add_col21c;
DELIMITER //
CREATE PROCEDURE _add_col21c()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'ativo'
    ) THEN
        ALTER TABLE clientes
          ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER uf;
        ALTER TABLE clientes ADD INDEX idx_cli_ativo (ativo);
    END IF;
END //
DELIMITER ;
CALL _add_col21c();
DROP PROCEDURE _add_col21c;

-- =====================================================================
--  CONFERENCIA:
--  SHOW COLUMNS FROM usuarios LIKE 'cnpj_norm';
--  SHOW INDEX FROM usuarios WHERE Key_name='uq_usuario_cnpj';
--  SELECT id, empresa, cnpj, cnpj_norm, email FROM usuarios
--   WHERE papel='revendedor';
-- =====================================================================
