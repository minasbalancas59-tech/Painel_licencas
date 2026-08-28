/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `acessos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acessos` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `fingerprint` varchar(80) NOT NULL,
  `licenca_id` int(11) DEFAULT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `tipo` enum('abertura','presenca','fechamento') NOT NULL DEFAULT 'abertura',
  `ts` datetime NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_acessos_fp_ts` (`fingerprint`,`ts`),
  KEY `ix_acessos_cliente_ts` (`cliente_id`,`ts`),
  KEY `ix_acessos_ts` (`ts`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ativacoes_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ativacoes_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `licenca_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `usuario_nome` varchar(120) DEFAULT NULL,
  `chave` varchar(35) DEFAULT NULL,
  `fingerprint` varchar(80) DEFAULT NULL,
  `produto_codigo` varchar(20) DEFAULT NULL,
  `tier_codigo` varchar(30) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `acao` varchar(40) NOT NULL,
  `resultado` varchar(20) NOT NULL,
  `detalhe` varchar(255) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_lic` (`licenca_id`),
  KEY `idx_log_data` (`criado_em`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clientes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clientes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `razao_social` varchar(160) NOT NULL,
  `cnpj` varchar(20) DEFAULT NULL,
  `contato` varchar(120) DEFAULT NULL,
  `telefone` varchar(40) DEFAULT NULL,
  `email` varchar(160) DEFAULT NULL,
  `observacao` text DEFAULT NULL,
  `criado_por` int(11) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_cli_user` (`criado_por`),
  CONSTRAINT `fk_cli_user` FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `licencas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `licencas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cliente_id` int(11) NOT NULL,
  `produto_id` int(11) DEFAULT NULL,
  `tier_id` int(11) DEFAULT NULL,
  `chave` varchar(35) NOT NULL,
  `fingerprint` varchar(80) DEFAULT NULL,
  `modulos` varchar(255) NOT NULL DEFAULT 'TBE',
  `emitido_em` date NOT NULL,
  `expira_em` date NOT NULL,
  `carencia_dias` smallint(6) NOT NULL DEFAULT 15,
  `status` enum('nova','ativa','revogada','expirada') NOT NULL DEFAULT 'nova',
  `tipo_ativacao` enum('online','offline') DEFAULT NULL,
  `ativada_em` datetime DEFAULT NULL,
  `criado_por` int(11) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `atualizado_em` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `chave` (`chave`),
  KEY `fk_lic_cli` (`cliente_id`),
  KEY `fk_lic_user` (`criado_por`),
  KEY `idx_lic_fp` (`fingerprint`),
  KEY `idx_lic_status` (`status`),
  KEY `fk_lic_produto` (`produto_id`),
  KEY `fk_lic_tier` (`tier_id`),
  CONSTRAINT `fk_lic_cli` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `fk_lic_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`),
  CONSTRAINT `fk_lic_tier` FOREIGN KEY (`tier_id`) REFERENCES `tiers` (`id`),
  CONSTRAINT `fk_lic_user` FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `maquinas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `maquinas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fingerprint` varchar(80) NOT NULL,
  `licenca_id` int(11) DEFAULT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `maq_nome` varchar(120) DEFAULT NULL,
  `maq_usuario` varchar(120) DEFAULT NULL,
  `maq_so` varchar(120) DEFAULT NULL,
  `origem` varchar(20) DEFAULT NULL,
  `primeiro_acesso` datetime DEFAULT NULL,
  `ultimo_acesso` datetime DEFAULT NULL,
  `aberturas` int(11) NOT NULL DEFAULT 0,
  `ip_ultimo` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_maquinas_fp` (`fingerprint`),
  KEY `idx_maquinas_cliente` (`cliente_id`),
  KEY `idx_maquinas_licenca` (`licenca_id`)
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `produtos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `produtos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) NOT NULL,
  `nome` varchar(80) NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tiers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tiers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `produto_id` int(11) NOT NULL,
  `codigo` varchar(30) NOT NULL,
  `nome` varchar(80) NOT NULL,
  `nivel` smallint(6) NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tiers_produto_codigo` (`produto_id`,`codigo`),
  UNIQUE KEY `uq_tiers_produto_nivel` (`produto_id`,`nivel`),
  CONSTRAINT `fk_tiers_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `papel` enum('admin','revendedor') NOT NULL DEFAULT 'admin',
  `ativo` tinyint(1) NOT NULL DEFAULT 1,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

