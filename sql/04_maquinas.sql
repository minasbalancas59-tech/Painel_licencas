-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 04: tabela de maquinas (ultimo acesso + contador de aberturas)
--
--  Banco: MariaDB 10.6
--  Aplicar depois da 03:
--      sudo mysql licencas < /root/licenca/sql/04_maquinas.sql
--
--  Uma linha por maquina (identificada pelo fingerprint). O "ping" de
--  abertura do Total Scale atualiza ultimo_acesso e incrementa aberturas.
-- =====================================================================

USE licencas;

CREATE TABLE IF NOT EXISTS maquinas (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    fingerprint   VARCHAR(80)  NOT NULL,
    licenca_id    INT          NULL,
    cliente_id    INT          NULL,
    maq_nome      VARCHAR(120) NULL,
    maq_usuario   VARCHAR(120) NULL,
    maq_so        VARCHAR(120) NULL,
    primeiro_acesso DATETIME   NULL,
    ultimo_acesso   DATETIME   NULL,
    aberturas     INT          NOT NULL DEFAULT 0,
    ip_ultimo     VARCHAR(45)  NULL,
    UNIQUE KEY uq_maquinas_fp (fingerprint),
    KEY idx_maquinas_cliente (cliente_id),
    KEY idx_maquinas_licenca (licenca_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  CONFERENCIA:
--  SHOW TABLES LIKE 'maquinas';
--  DESCRIBE maquinas;
-- =====================================================================
