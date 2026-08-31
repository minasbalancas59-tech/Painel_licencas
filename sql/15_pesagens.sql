-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 15: volume mensal de pesagens
--
--  Aplicar depois da 14:
--      mysql licencas < sql/15_pesagens.sql
--
--  POR QUE ESTA TABELA NAO E EXPURGADA
--  A `acessos` guarda sinais de uso e cresce sem limite - por isso tem
--  retencao de 400 dias. Esta aqui e outra coisa: e o historico
--  comercial do cliente, o argumento da renovacao. Uma linha por
--  cliente por mes; dez anos de um cliente cabem em 120 linhas.
--
--  ORIGEM DO DADO
--  O TS5 conta as pesagens da propria base (tabelamestre, pelo campo
--  SAIDA) e envia o total do mes no ping de presenca. O servidor grava
--  o MAIOR valor visto no mes, nao a soma: o cliente reporta um total
--  acumulado a cada ping, entao somar multiplicaria o numero.
--
--  ATENCAO: nao ha historico retroativo. O volume comeca a ser
--  registrado quando a versao nova do TS5 chegar em cada cliente.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pesagens_mensal (
    cliente_id   INT       NOT NULL,
    ano_mes      CHAR(7)   NOT NULL,   -- '2026-08'
    fingerprint  VARCHAR(80) NOT NULL, -- uma balanca pode ter varios PCs
    pesagens     INT       NOT NULL DEFAULT 0,
    licenca_id   INT       NULL,
    produto      VARCHAR(20) NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                            ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cliente_id, ano_mes, fingerprint),
    INDEX idx_pesag_mes (ano_mes),
    INDEX idx_pesag_cli (cliente_id, ano_mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  CONSULTAS UTEIS
--
--  volume mensal de um cliente:
--    SELECT ano_mes, SUM(pesagens) FROM pesagens_mensal
--     WHERE cliente_id = 1 GROUP BY ano_mes ORDER BY ano_mes;
--
--  media dos ultimos 6 meses, por cliente (para renovacao):
--    SELECT c.razao_social, ROUND(AVG(m.total)) AS media_mes
--      FROM (SELECT cliente_id, ano_mes, SUM(pesagens) AS total
--              FROM pesagens_mensal
--             WHERE ano_mes >= DATE_FORMAT(DATE_SUB(CURDATE(),
--                                          INTERVAL 6 MONTH), '%Y-%m')
--             GROUP BY cliente_id, ano_mes) m
--      JOIN clientes c ON c.id = m.cliente_id
--     GROUP BY c.id ORDER BY media_mes DESC;
--
--  quem esta caindo de uso (candidato a churn):
--    compare o mes atual com a media dos 3 anteriores
-- =====================================================================

-- =====================================================================
--  CONFERENCIA:
--  SHOW TABLES LIKE 'pesagens_mensal';
--  SELECT * FROM pesagens_mensal ORDER BY ano_mes DESC LIMIT 10;
-- =====================================================================
