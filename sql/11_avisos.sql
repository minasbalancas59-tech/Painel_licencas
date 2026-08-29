-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 11: controle de avisos de vencimento
--
--  Aplicar depois da 10:
--      mysql licencas < sql/11_avisos.sql
--
--  Registra qual aviso ja foi enviado para qual licenca, para o cron
--  rodar todo dia sem mandar o mesmo e-mail repetido.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS avisos_vencimento (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    licenca_id  INT         NOT NULL,
    marco       VARCHAR(10) NOT NULL,  -- '30','15','7','0','vencida'
    expira_em   DATE        NOT NULL,  -- vencimento no momento do aviso
    destino     VARCHAR(160) NULL,     -- para quem foi
    enviado_em  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_aviso_lic FOREIGN KEY (licenca_id)
        REFERENCES licencas(id) ON DELETE CASCADE,
    -- a chave unica inclui expira_em: se a licenca for RENOVADA, a data
    -- muda e os avisos do novo ciclo voltam a ser permitidos
    UNIQUE KEY uq_aviso (licenca_id, marco, expira_em),
    INDEX idx_aviso_data (enviado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  CONFERENCIA:
--  SHOW TABLES LIKE 'avisos_vencimento';
--  SELECT marco, COUNT(*) FROM avisos_vencimento GROUP BY marco;
-- =====================================================================
