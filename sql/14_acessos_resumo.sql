-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 14: resumo mensal de acessos, para o expurgo nao apagar
--               o historico de longo prazo
--
--  Aplicar depois da 13:
--      mysql licencas < sql/14_acessos_resumo.sql
--
--  POR QUE ISTO EXISTE
--  A tabela `acessos` guarda cada ping: com 100 maquinas em 12h/dia
--  sao ~1,8 milhao de linhas por ano. Nao e um volume que assuste o
--  MariaDB, mas cresce para sempre - e o backup diario vai junto.
--
--  A retencao ficou em 400 dias porque duas telas precisam disso:
--    cliente.php  filtro de 365 dias
--    maquina.php  grafico dos ultimos 12 meses
--  Cortar em 180 dias, como e comum, quebraria as duas.
--
--  Antes de apagar, o cron consolida o mes aqui. Assim o historico de
--  anos anteriores continua disponivel, ocupando uma linha por maquina
--  por mes em vez de milhares.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS acessos_resumo (
    fingerprint  VARCHAR(80) NOT NULL,
    ano_mes      CHAR(7)     NOT NULL,   -- '2026-08'
    aberturas    INT         NOT NULL DEFAULT 0,
    sinais       INT         NOT NULL DEFAULT 0,   -- todos os tipos
    dias_ativos  SMALLINT    NOT NULL DEFAULT 0,
    primeiro_dia DATE        NULL,
    ultimo_dia   DATE        NULL,
    cliente_id   INT         NULL,
    licenca_id   INT         NULL,
    PRIMARY KEY (fingerprint, ano_mes),
    INDEX idx_resumo_mes (ano_mes),
    INDEX idx_resumo_cli (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- indice que o expurgo usa para varrer por data
DROP PROCEDURE IF EXISTS _add_idx14;
DELIMITER //
CREATE PROCEDURE _add_idx14()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'acessos' AND INDEX_NAME = 'idx_acessos_ts'
    ) THEN
        ALTER TABLE acessos ADD INDEX idx_acessos_ts (ts);
    END IF;
END //
DELIMITER ;
CALL _add_idx14();
DROP PROCEDURE _add_idx14;

-- =====================================================================
--  CONFERENCIA:
--  SHOW TABLES LIKE 'acessos_resumo';
--  SHOW INDEX FROM acessos WHERE Key_name='idx_acessos_ts';
--  SELECT COUNT(*) FROM acessos;
-- =====================================================================
