-- =====================================================================
-- MESA DE AYUDA / HELPDESK — Gobierno de Chile
-- schema.sql  →  Importar en phpMyAdmin (cPanel) sobre una BD vacía
-- Credenciales semilla: admin/admin123 · supervisor/admin123 · tecnico/admin123
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------- USUARIOS (panel interno) -----------------------
DROP TABLE IF EXISTS `usuarios`;
CREATE TABLE `usuarios` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`        VARCHAR(120) NOT NULL,
  `usuario`       VARCHAR(60)  NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `email`         VARCHAR(150) DEFAULT NULL,
  `rol`           ENUM('admin','supervisor','tecnico') NOT NULL DEFAULT 'tecnico',
  `activo`        TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hash bcrypt REAL de "admin123" (válido para los tres usuarios semilla)
INSERT INTO `usuarios` (`nombre`, `usuario`, `password_hash`, `email`, `rol`) VALUES
('Administrador General', 'admin',      '$2y$10$cgGM87KwdgiOw3qrB5SS9eGBuqAx2cCF83lawDOpcFqlya996uhpm', 'admin@helpdesk.gob.cl',      'admin'),
('Supervisor de Mesa',    'supervisor', '$2y$10$cgGM87KwdgiOw3qrB5SS9eGBuqAx2cCF83lawDOpcFqlya996uhpm', 'supervisor@helpdesk.gob.cl', 'supervisor'),
('Técnico Soporte',       'tecnico',    '$2y$10$cgGM87KwdgiOw3qrB5SS9eGBuqAx2cCF83lawDOpcFqlya996uhpm', 'tecnico@helpdesk.gob.cl',    'tecnico');

-- ----------------------- CATEGORÍAS / KB -----------------------
DROP TABLE IF EXISTS `categorias`;
CREATE TABLE `categorias` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre`      VARCHAR(100) NOT NULL UNIQUE,
  `descripcion` VARCHAR(255) DEFAULT NULL,
  `icono`       VARCHAR(40)  DEFAULT 'soporte',
  `articulos`   INT UNSIGNED NOT NULL DEFAULT 0,
  `orden`       INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `categorias` (`nombre`, `descripcion`, `icono`, `articulos`, `orden`) VALUES
('Conectividad',            'Red, Internet, VPN y caídas de servicio', 'wifi',      14, 1),
('Cuentas y Accesos',       'Alturas de sistema, contraseñas, permisos', 'lock',      21, 2),
('Soporte Técnico',         'Software, correo, impresoras y aplicaciones', 'soporte',  33, 3),
('Hardware',                'Equipos, periféricos e insumos',           'hardware',   12, 4),
('Seguridad Informática',   'Phishing, incidentes y buenas prácticas',  'shield',      9, 5),
('Correo Institucional',    'Buzón, reenvíos y listas de distribución', 'correo',      18, 6);

-- ----------------------- TICKETS -----------------------
DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo`         VARCHAR(30)  NOT NULL UNIQUE,       -- TKT-2026-00001
  `token_consulta` CHAR(12)     NOT NULL UNIQUE,       -- código ciudadano p/ consultar
  `solicitante`    VARCHAR(120) NOT NULL,
  `email`          VARCHAR(150) NOT NULL,
  `asunto`         VARCHAR(200) NOT NULL,
  `descripcion`    TEXT NOT NULL,
  `categoria_id`   INT UNSIGNED DEFAULT NULL,
  `estado`         ENUM('abierto','en_proceso','resuelto','cerrado') NOT NULL DEFAULT 'abierto',
  `prioridad`      ENUM('baja','media','alta','critica') NOT NULL DEFAULT 'media',
  `asignado_a`     INT UNSIGNED DEFAULT NULL,
  `adjunto`        VARCHAR(255) DEFAULT NULL,
  `creado_en`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_categoria` (`categoria_id`),
  KEY `idx_asignado` (`asignado_a`),
  CONSTRAINT `fk_ticket_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ticket_usuario`   FOREIGN KEY (`asignado_a`)   REFERENCES `usuarios`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tickets`
(`codigo`,`token_consulta`,`solicitante`,`email`,`asunto`,`descripcion`,`categoria_id`,`estado`,`prioridad`,`asignado_a`,`creado_en`) VALUES
('TKT-2026-00001','A7X3K9P2M1Q4','María Pérez','maria.perez@correo.gob.cl','No puedo acceder al correo institucional','Desde ayer el buzón solicita la contraseña y luego muestra error 500.',6,'en_proceso','alta',3,'2026-10-10 09:12:00'),
('TKT-2026-00002','B2M8N5Q1R7T9','Juan Rojas','juan.rojas@correo.gob.cl','Impresora del 3er piso sin respuesta','La impresora HP aparece "sin conexión" en todos los equipos del piso.',3,'abierto','media',NULL,'2026-10-12 11:40:00'),
('TKT-2026-00013','C9F4H2J6K8L1','Ana Soto','ana.soto@correo.gob.cl','Corte de conectividad VPN','La VPN se desconecta cada 10 minutos desde el lunes, impidiendo teletrabajo.',1,'abierto','critica',3,'2026-10-13 08:05:00'),
('TKT-2026-00004','D3G7S1A5P0Q8','Luis Fuentes','luis.fuentes@correo.gob.cl','Solicitud de altura de sistema SIAC','Se requiere dar de baja a dos funcionarios retirados.',2,'resuelto','media',2,'2026-10-08 15:22:00'),
('TKT-2026-00005','E5R2T8Y4U6I0','Carla Núñez','carla.nunez@correo.gob.cl','Monitor con parpadeos','El monitor del puesto 14 parpadea, podría ser drivers o cable HDMI.',4,'cerrado','baja',3,'2026-10-05 10:00:00'),
('TKT-2026-00006','F8Q1W3E5R7T2','Pedro Salas','pedro.salas@correo.gob.cl','Posible phishing recibido','Llegó un correo falso de "Tesorería" pidiendo clave. No hice clic, pero aviso.',5,'en_proceso','alta',2,'2026-10-13 16:45:00');

-- ----------------------- HISTORIAL / COMENTARIOS -----------------------
DROP TABLE IF EXISTS `ticket_historial`;
CREATE TABLE `ticket_historial` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL,
  `autor`      VARCHAR(120) NOT NULL,
  `tipo`       ENUM('comentario','ciudadano','estado','asignacion','creacion') NOT NULL DEFAULT 'comentario',
  `mensaje`    TEXT NOT NULL,
  `creado_en`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`),
  CONSTRAINT `fk_hist_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ticket_historial` (`ticket_id`,`autor`,`tipo`,`mensaje`,`creado_en`) VALUES
(1,'Sistema','creacion','Ticket creado automáticamente desde el Portal Ciudadano.','2026-10-10 09:12:00'),
(1,'Técnico Soporte','comentario','Se verificó el servidor de correo: su cuenta quedó bloqueada por intentos fallidos. Se libera y se fuerza cambio de clave.','2026-10-10 12:30:00'),
(1,'María Pérez','ciudadano','Gracias, ya pude entrar. ¿Queda cerrado?','2026-10-11 08:15:00'),
(3,'Sistema','creacion','Ticket creado automáticamente desde el Portal Ciudadano.','2026-10-13 08:05:00'),
(3,'Supervisor de Mesa','estado','Estado cambiado a ABIERTO con prioridad CRÍTICA. Escalado a equipo de Redes.','2026-10-13 09:00:00'),
(4,'Técnico Soporte','comentario','Bajas de cuentas realizadas y confirmadas por jefatura.','2026-10-08 17:00:00'),
(4,'Sistema','estado','Estado cambiado a RESUELTO.','2026-10-08 17:05:00'),
(6,'Supervisor de Mesa','comentario','Se aisló el correo malicioso y se envió alerta general al personal.','2026-10-13 18:10:00');

SET FOREIGN_KEY_CHECKS = 1;

-- FIN DEL SCRIPT
