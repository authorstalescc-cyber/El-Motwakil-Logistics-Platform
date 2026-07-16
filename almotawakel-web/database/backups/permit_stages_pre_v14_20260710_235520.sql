-- MySQL dump 10.13  Distrib 9.6.0, for macos26.2 (arm64)
--
-- Host: 127.0.0.1    Database: almotawakel
-- ------------------------------------------------------
-- Server version	9.6.0

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
SET @MYSQLDUMP_TEMP_LOG_BIN = @@SESSION.SQL_LOG_BIN;
SET @@SESSION.SQL_LOG_BIN= 0;

--
-- GTID state at the beginning of the backup 
--

SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ '6b0c8a5c-16ca-11f1-af17-e75e2392738a:1-18053';

--
-- Table structure for table `permit_stages`
--

DROP TABLE IF EXISTS `permit_stages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permit_stages` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `permit_id` int unsigned NOT NULL,
  `movement_id` int unsigned DEFAULT NULL,
  `stage_key` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `actual_amount` decimal(14,2) DEFAULT NULL,
  `entry_date` date DEFAULT NULL,
  `ref_no` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'done',
  `attachment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_visa` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_permit` (`permit_id`),
  KEY `idx_stage` (`stage_key`),
  KEY `idx_date` (`entry_date`),
  KEY `idx_movement` (`movement_id`),
  CONSTRAINT `fk_stage_permit` FOREIGN KEY (`permit_id`) REFERENCES `permits` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `permit_stages`
--

LOCK TABLES `permit_stages` WRITE;
/*!40000 ALTER TABLE `permit_stages` DISABLE KEYS */;
INSERT INTO `permit_stages` VALUES (1,2,2,'takhsis',NULL,1600.00,NULL,'2026-07-05',NULL,NULL,'done',NULL,'2026-07-08 14:06:53',0),(2,2,2,'hemola',NULL,5750.00,NULL,'2026-07-07',NULL,NULL,'done',NULL,'2026-07-08 14:07:29',0),(4,5,5,'custom','expense',1050.00,NULL,'2026-07-07',NULL,'هيئة نيو بيبي','done',NULL,'2026-07-08 15:34:13',0),(5,5,5,'custom','expense',100.00,NULL,'2026-07-07',NULL,'متمن','done',NULL,'2026-07-08 15:34:40',0),(6,4,4,'custom','expense',860.00,NULL,'2026-07-07',NULL,'نبطشية','done',NULL,'2026-07-08 15:36:42',0),(7,3,3,'custom','expense',860.00,NULL,'2026-07-07',NULL,'نبطشية','done',NULL,'2026-07-08 15:37:13',0),(8,4,4,'fees',NULL,1800.00,NULL,'2026-07-07',NULL,'ايصال ساحل الوطنية','done',NULL,'2026-07-08 15:38:19',0),(10,2,2,'fees',NULL,30.00,NULL,'2026-07-07',NULL,'اكرامية الامن ساحة العنتبلي','done',NULL,'2026-07-08 17:00:58',0),(22,5,5,'ta3teeq',NULL,1255.00,NULL,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 15:58:21',0),(23,5,5,'ta3teeq',NULL,1255.00,NULL,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 15:58:37',1),(25,2,2,'ta3teeq',NULL,26304.00,NULL,'2026-07-08',NULL,'إيصال تعتيق صينية','done',NULL,'2026-07-09 16:01:08',1),(26,2,2,'fees',NULL,1315.00,NULL,'2026-07-08',NULL,'ايصال جيش','done',NULL,'2026-07-09 16:02:10',0),(27,2,2,'fees',NULL,1350.00,NULL,'2026-07-08',NULL,'ايصال جيش','done',NULL,'2026-07-09 16:02:29',0),(28,2,2,'hay2a',NULL,780.00,771.72,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:03:25',0),(30,2,2,'fees',NULL,1120.00,1120.00,'2026-07-08',NULL,'٢ ايصال طرق وكباري','done',NULL,'2026-07-09 16:05:20',0),(31,2,2,'fees',NULL,200.00,NULL,'2026-07-08',NULL,'2 ايصال محافظة','done',NULL,'2026-07-09 16:05:54',0),(32,8,8,'fees',NULL,500.00,NULL,'2026-07-07',NULL,'ايصال لا مانع','done',NULL,'2026-07-09 16:10:18',1),(33,8,8,'hay2a',NULL,200.00,195.07,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:10:50',0),(34,8,8,'ta3teeq',NULL,1470.00,1467.18,'2026-07-09',NULL,NULL,'done',NULL,'2026-07-09 16:12:51',0),(35,8,8,'fees',NULL,1105.00,NULL,'2026-07-09',NULL,'ايضال جيش','done',NULL,'2026-07-09 16:13:17',0),(36,9,9,'takhsis',NULL,650.00,600.00,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:16:16',1),(37,10,10,'hay2a',NULL,315.00,321.06,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:19:41',0),(38,10,10,'hay2a',NULL,315.00,289.00,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:24:30',0),(39,9,9,'custom','expense',200.00,NULL,'2026-07-08',NULL,'تخصيص بدون إيصال (نثريات)','done',NULL,'2026-07-09 16:34:58',0),(40,15,11,'takhsis',NULL,485.00,485.00,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:35:31',0),(41,12,12,'fees',NULL,200.00,NULL,'2026-07-08',NULL,'ايصال لا مانع','done',NULL,'2026-07-09 16:42:36',0),(42,14,13,'takhsis',NULL,485.00,NULL,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:43:34',0),(43,14,13,'hemola',NULL,1140.00,NULL,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:43:52',0),(44,9,9,'hemola',NULL,1127.92,NULL,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:44:32',1),(45,9,9,'custom','expense',20.00,NULL,'2026-07-08',NULL,'مستودعات بدون إيصال','done',NULL,'2026-07-09 16:45:25',0),(46,13,14,'hemola',NULL,1820.00,1817.00,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:47:04',0),(47,13,14,'custom','expense',50.00,NULL,'2026-07-08',NULL,'إكرامية ساحة فلو - تحميل بدون نبطشية','done',NULL,'2026-07-09 16:47:27',0),(48,14,13,'custom','expense',30.00,NULL,'2026-07-08',NULL,'إكرامية ساحة الأركس - تحميل كونتر نضيف','done',NULL,'2026-07-09 16:48:04',0),(50,11,15,'hemola',NULL,1335.00,1331.00,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:50:34',0),(51,9,9,'hay2a',NULL,50.00,45.15,'2026-07-08',NULL,'هيئة فوارغ','done',NULL,'2026-07-09 16:51:11',0),(52,9,9,'custom','expense',860.00,850.00,'2026-07-08',NULL,'نبطشية','done',NULL,'2026-07-09 16:51:45',0),(53,11,15,'custom','expense',860.00,850.00,'2026-07-08',NULL,'ايصال نبطشية','done',NULL,'2026-07-09 16:52:30',0),(54,12,12,'hay2a',NULL,240.00,231.93,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 16:53:14',0),(55,5,5,'ta3teeq',NULL,5430.00,5430.00,'2026-07-08',NULL,NULL,'done',NULL,'2026-07-09 17:36:06',1),(56,11,15,'custom','expense',265.00,NULL,'2026-07-08',NULL,'نبطشية ساحة','done',NULL,'2026-07-09 17:44:07',0);
/*!40000 ALTER TABLE `permit_stages` ENABLE KEYS */;
UNLOCK TABLES;
SET @@SESSION.SQL_LOG_BIN = @MYSQLDUMP_TEMP_LOG_BIN;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-07-10 23:55:20
