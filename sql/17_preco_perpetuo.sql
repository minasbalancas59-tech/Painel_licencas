-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 17: dois precos por tipo de licenca
--
--  Aplicar depois da 16:
--      mysql licencas < sql/17_preco_perpetuo.sql
--
--  POR QUE DOIS PRECOS
--
--  Voce vende das duas formas, e os valores nao se calculam um do
--  outro:
--
--    preco_base      ANUIDADE - quanto custa um ano de uso. O sistema
--                    aplica proporcional: 6 meses = metade, 24 = dobro.
--
--    preco_perpetuo  VENDA DO SISTEMA - pagamento unico, licenca sem
--                    prazo pratico (emitida com 10 anos).
--
--  Um erro comum seria guardar so a anuidade e multiplicar por N anos
--  para chegar na perpetua. Nao funciona: a perpetua costuma valer 3 a
--  5 anuidades, nao 10 - senao ninguem compraria.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS _add_col17;
DELIMITER //
CREATE PROCEDURE _add_col17(
    IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table AND COLUMN_NAME = p_col
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
    END IF;
END //
DELIMITER ;

CALL _add_col17('tiers','preco_perpetuo',
    'preco_perpetuo DECIMAL(10,2) NULL AFTER preco_base');

-- registra qual modelo foi vendido, para separar receita recorrente de
-- receita unica no financeiro
CALL _add_col17('licencas','modelo',
    "modelo ENUM('assinatura','perpetua') NOT NULL DEFAULT 'assinatura'
     AFTER tipo_licenca");
CALL _add_col17('financeiro_mov','modelo',
    "modelo ENUM('assinatura','perpetua') NOT NULL DEFAULT 'assinatura'
     AFTER tipo");

DROP PROCEDURE _add_col17;

-- licencas antigas com 10 anos de validade eram, na pratica, perpetuas
UPDATE licencas
   SET modelo = 'perpetua'
 WHERE modelo = 'assinatura'
   AND TIMESTAMPDIFF(MONTH, emitido_em, expira_em) >= 100;

-- =====================================================================
--  CONFERENCIA:
--  SELECT p.codigo, t.codigo, t.preco_base, t.preco_perpetuo
--    FROM tiers t JOIN produtos p ON p.id=t.produto_id ORDER BY 1, t.nivel;
--  SELECT modelo, COUNT(*) FROM licencas GROUP BY modelo;
-- =====================================================================
