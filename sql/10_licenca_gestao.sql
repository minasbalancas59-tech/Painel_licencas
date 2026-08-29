-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 10: anotacao interna e historico de renovacao
--
--  Aplicar depois da 09:
--      mysql licencas < sql/10_licenca_gestao.sql
--
--  CONTEXTO TECNICO
--  A licenca entregue ao cliente e um JSON ASSINADO. Alterar colunas
--  aqui NAO muda o arquivo que ja esta na maquina dele: o api/ativar.php
--  re-assina com os valores atuais a cada chamada, e o cliente so recebe
--  a versao nova na proxima revalidacao (ciclo de 7 dias no uRevalidacao).
--
--  Por isso o painel separa:
--    EDITAR  - campos que nao entram no payload assinado (vinculo,
--              limite de transferencias, anotacao interna)
--    RENOVAR - estende a validade; propaga na revalidacao, com log
--
--  Produto, tier e modulos NAO sao editaveis: mudariam o que foi
--  contratado sem rastro. Para isso, revogue e emita outra.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _add_col10;
DELIMITER //
CREATE PROCEDURE _add_col10(
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

-- anotacao interna (nao vai para o cliente, nao entra no payload)
CALL _add_col10('licencas','observacao',
    'observacao TEXT NULL AFTER obs_revogacao');

-- historico de renovacao: quantas vezes e quando
CALL _add_col10('licencas','renovacoes',
    'renovacoes SMALLINT NOT NULL DEFAULT 0 AFTER max_transferencias');
CALL _add_col10('licencas','renovada_em',
    'renovada_em DATETIME NULL AFTER renovacoes');

-- indices para os filtros da tela de licencas
DROP PROCEDURE IF EXISTS _add_idx10;
DELIMITER //
CREATE PROCEDURE _add_idx10(
    IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_cols VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table AND INDEX_NAME = p_name
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD INDEX ',
                        p_name, ' (', p_cols, ')');
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

CALL _add_idx10('licencas','idx_lic_expira','expira_em');
CALL _add_idx10('licencas','idx_lic_emitido','emitido_em');
CALL _add_idx10('licencas','idx_lic_status','status');

DROP PROCEDURE _add_col10;
DROP PROCEDURE _add_idx10;

-- =====================================================================
--  CONFERENCIA:
--  SHOW COLUMNS FROM licencas LIKE 'renovacoes';
--  SHOW INDEX FROM licencas WHERE Key_name LIKE 'idx_lic_%';
-- =====================================================================
