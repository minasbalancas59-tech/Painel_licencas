-- =====================================================================
--  Sistema de Licenciamento Total Scale
--  Migracao 18: precos especiais por cliente e por revendedor
--
--  Aplicar depois da 17:
--      mysql licencas < sql/18_precos_especiais.sql
--
--  HIERARQUIA DE PRECOS - do mais especifico ao mais geral
--
--    1. Preco especial do CLIENTE      (venda direta)
--    2. Preco especial do REVENDEDOR   (venda por ele)
--    3. Tabela menos desconto_revenda  (venda por revendedor sem
--                                       preco especial)
--    4. Tabela cheia                   (venda direta sem acordo)
--
--  O mais especifico sempre vence. Se um revendedor tem 20% de
--  desconto geral E um preco especial para o tier Extreme, o Extreme
--  sai pelo preco especial - o percentual nao e aplicado de novo em
--  cima dele.
--
--  Isso importa: aplicar os dois daria desconto sobre desconto, e o
--  operador so perceberia ao conferir a margem no fim do mes.
-- =====================================================================

USE licencas;
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS precos_especiais (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tier_id     INT NOT NULL,
    -- a quem se aplica
    alvo_tipo   ENUM('cliente','revendedor') NOT NULL,
    alvo_id     INT NOT NULL,
    -- NULL em qualquer um dos dois = usa o valor da tabela para aquele
    -- modelo. Permite acordo so na anuidade, so na perpetua, ou nos dois
    preco_base     DECIMAL(10,2) NULL,
    preco_perpetuo DECIMAL(10,2) NULL,
    -- acordo com prazo: promocao, condicao de lancamento, contrato anual
    vigencia_ate   DATE          NULL,
    observacao     VARCHAR(255)  NULL,
    criado_por     INT           NULL,
    criado_em      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                 ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_pe_tier FOREIGN KEY (tier_id)
        REFERENCES tiers(id) ON DELETE CASCADE,
    -- um preco por tier por alvo; trocar sobrescreve
    UNIQUE KEY uq_pe (tier_id, alvo_tipo, alvo_id),
    INDEX idx_pe_alvo (alvo_tipo, alvo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  CONSULTA DE APOIO - qual preco vale para um alvo
--
--  SELECT t.id, t.nome,
--         COALESCE(pe.preco_base, t.preco_base) AS anuidade,
--         COALESCE(pe.preco_perpetuo, t.preco_perpetuo) AS perpetua,
--         pe.id IS NOT NULL AS tem_acordo
--    FROM tiers t
--    LEFT JOIN precos_especiais pe
--           ON pe.tier_id = t.id
--          AND pe.alvo_tipo = 'cliente' AND pe.alvo_id = 1
--          AND (pe.vigencia_ate IS NULL OR pe.vigencia_ate >= CURDATE())
--   WHERE t.ativo = 1;
-- =====================================================================

-- =====================================================================
--  CONFERENCIA:
--  SHOW TABLES LIKE 'precos_especiais';
--  SELECT COUNT(*) FROM precos_especiais;
-- =====================================================================
