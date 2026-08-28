-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 06: motivo da revogacao
--
--  Aplicar depois da 05:
--      mysql licencas < sql/06_revogacao.sql
--
--  Aditiva e idempotente. Licencas ja revogadas ficam com motivo NULL
--  (exibido como "nao informado" no painel) - o historico existente
--  nao se perde nem se inventa.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _add_col6;
DELIMITER //
CREATE PROCEDURE _add_col6(
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

-- motivo_revogacao: categoria fechada, para dar relatorio consistente
--   inadimplencia   - cliente parou de pagar
--   cancelamento    - contrato encerrado a pedido do cliente
--   troca_licenca   - substituida por outra (upgrade, correcao)
--   uso_indevido    - pirataria, compartilhamento, quebra de contrato
--   erro_emissao    - emitida errada (cliente/tier/validade)
--   outro           - detalhar na observacao
CALL _add_col6('licencas','motivo_revogacao',
    "motivo_revogacao ENUM('inadimplencia','cancelamento','troca_licenca',
     'uso_indevido','erro_emissao','outro') NULL AFTER status");

-- texto livre: o "por que" que a categoria nao cobre
CALL _add_col6('licencas','obs_revogacao',
    'obs_revogacao TEXT NULL AFTER motivo_revogacao');

-- quem revogou e quando (o log tambem registra, mas aqui fica junto da
-- licenca, que e onde se olha na hora de responder ao cliente)
CALL _add_col6('licencas','revogada_em',
    'revogada_em DATETIME NULL AFTER obs_revogacao');
CALL _add_col6('licencas','revogada_por',
    'revogada_por INT NULL AFTER revogada_em');

DROP PROCEDURE _add_col6;

-- =====================================================================
--  CONFERENCIA:
--  SHOW COLUMNS FROM licencas LIKE '%revoga%';
--  -> motivo_revogacao, obs_revogacao, revogada_em, revogada_por
-- =====================================================================
