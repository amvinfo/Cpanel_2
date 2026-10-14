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
('María González',  'admin',      '$2y$10$cgGM87KwdgiOw3qrB5SS9eGBuqAx2cCF83lawDOpcFqlya996uhpm', 'admin@helpdesk.gob.cl',      'admin'),
('Ana Riquelme',    'supervisor', '$2y$10$cgGM87KwdgiOw3qrB5SS9eGBuqAx2cCF83lawDOpcFqlya996uhpm', 'supervisor@helpdesk.gob.cl', 'supervisor'),
('Carlos Muñoz',    'tecnico',    '$2y$10$cgGM87KwdgiOw3qrB5SS9eGBuqAx2cCF83lawDOpcFqlya996uhpm', 'tecnico@helpdesk.gob.cl',    'tecnico');

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
  `codigo`         VARCHAR(30)  NOT NULL UNIQUE,       -- TK-XXXXXXXX
  `token_consulta` VARCHAR(14)  NOT NULL UNIQUE,       -- código ciudadano p/ consultar (XXXX-XXXX-XXXX)
  `solicitante`    VARCHAR(120) NOT NULL,
  `email`          VARCHAR(150) NOT NULL,
  `asunto`         VARCHAR(200) NOT NULL,
  `descripcion`    TEXT NOT NULL,
  `categoria_id`   INT UNSIGNED DEFAULT NULL,
  `estado`         ENUM('nuevo','abierto','asignado','en_atencion','esperando_solicitante',
                        'esperando_tercero','escalado','resuelto','cerrado','reabierto','cancelado')
                   NOT NULL DEFAULT 'nuevo',
  `prioridad`      ENUM('baja','media','alta','urgente') NOT NULL DEFAULT 'media',
  `tipo`           VARCHAR(40)  DEFAULT NULL,           -- Incidencia / Requerimiento / Consulta...
  `origen`         ENUM('publico','interno') NOT NULL DEFAULT 'publico',
  `departamento`   VARCHAR(120) DEFAULT NULL,
  `ubicacion`      VARCHAR(120) DEFAULT NULL,
  `asignado_a`     INT UNSIGNED DEFAULT NULL,
  `creado_por`     INT UNSIGNED DEFAULT NULL,           -- usuario interno que creó un ticket interno
  `adjunto`        VARCHAR(255) DEFAULT NULL,
  `creado_en`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_estado` (`estado`),
  KEY `idx_prioridad` (`prioridad`),
  KEY `idx_categoria` (`categoria_id`),
  KEY `idx_asignado` (`asignado_a`),
  CONSTRAINT `fk_ticket_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `categorias`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ticket_usuario`   FOREIGN KEY (`asignado_a`)   REFERENCES `usuarios`(`id`)   ON DELETE SET NULL,
  CONSTRAINT `fk_ticket_creador`   FOREIGN KEY (`creado_por`)   REFERENCES `usuarios`(`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tickets`
(`codigo`,`token_consulta`,`solicitante`,`email`,`asunto`,`descripcion`,`categoria_id`,`estado`,`prioridad`,`tipo`,`origen`,`departamento`,`ubicacion`,`asignado_a`,`creado_por`,`creado_en`) VALUES
('TK-AB12CD34','A7X3-K9P2-M1Q4','Juan Pérez','juan.perez@correo.gob.cl','No puedo ingresar al sistema de RRHH','Al intentar autenticarme en el portal de RRHH el sistema muestra "Usuario o contraseña incorrectos" pese a que mi clave es válida en el resto de los sistemas internos.',2,'asignado','alta','Incidencia','publico','Dirección de Operaciones','Edificio Central — Piso 2',3,NULL,'2026-10-13 09:12:00'),
('TK-EF56GH78','B2M8-N5Q1-R7T9','Karla Díaz','karla.diaz@correo.gob.cl','Problemas de acceso y contraseñas','Necesito restablecer mi contraseña porque recibí una alerta de actividad sospechosa en mi cuenta.',2,'en_atencion','media','Solicitud de acceso','publico','Unidad de Personas','Remoto / Teletrabajo',3,NULL,'2026-10-13 11:40:00'),
('TKT-2026-00013','C9F4-H2J6-K8L1','Ana Soto','ana.soto@correo.gob.cl','Corte de conectividad VPN','La VPN se desconecta cada 10 minutos desde el lunes, impidiendo teletrabajo.',1,'escalado','urgente','Incidencia','publico','Dirección de Planificación','Anexo A',3,NULL,'2026-10-13 08:05:00'),
('TK-IJ90KL12','D3G7-S1A5-P0Q8','Luis Fuentes','luis.fuentes@correo.gob.cl','Solicitud de altura de sistema SIAC','Se requiere dar de baja a dos funcionarios retirados.',2,'resuelto','media','Solicitud de acceso','publico','Dirección de RRHH','Edificio Central — Piso 1',2,NULL,'2026-10-08 15:22:00'),
('TK-MN34OP56','E5R2-T8Y4-U6I0','Carla Núñez','carla.nunez@correo.gob.cl','Monitor con parpadeos','El monitor del puesto 14 parpadea, podría ser drivers o cable HDMI.',4,'cerrado','baja','Incidencia','publico','Dirección de Finanzas','Edificio Central — Piso 3',3,NULL,'2026-10-05 10:00:00'),
('TK-QR78ST90','F8Q1-W3E5-R7T2','Pedro Salas','pedro.salas@correo.gob.cl','Posible phishing recibido','Llegó un correo falso de "Tesorería" pidiendo clave. No hice clic, pero aviso.',5,'esperando_solicitante','alta','Consulta','publico','Unidad de Auditoría','Remoto / Teletrabajo',2,NULL,'2026-10-13 16:45:00'),
('TK-INT01AB','I2N4-T6E8-R10A','Administrador del Sistema','admin@mesadeayuda.cl','Renovación de certificados SSL del portal','Los certificados del portal institucional vencen el 30-10-2026. Se debe coordinar renovación e instalación antes de esa fecha.',3,'nuevo','urgente','Mantención','interno','Dirección de Operaciones','Edificio Central — Piso 1',3,1,'2026-10-14 10:05:00'),
('TK-INT02CD','Z9Y8-X7W6-V5U4','Supervisor de Mesa','supervisor@helpdesk.gob.cl','Auditoría mensual de accesos privilegiados','Corresponde ejecutar la revisión trimestral de cuentas con permisos elevados en los sistemas financieros.',2,'nuevo','media','Requerimiento','interno','Unidad de Auditoría','Edificio Central — Piso 2',NULL,2,'2026-10-14 11:30:00');

-- ----------------------- HISTORIAL / COMUNICACIONES / LOG -----------------------
DROP TABLE IF EXISTS `ticket_historial`;
CREATE TABLE `ticket_historial` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ticket_id`  INT UNSIGNED NOT NULL,
  `autor`      VARCHAR(120) NOT NULL,
  `tipo`       ENUM('respuesta','nota_interna','ciudadano','estado','asignacion','creacion') NOT NULL DEFAULT 'respuesta',
  `mensaje`    TEXT NOT NULL,
  `creado_en`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ticket` (`ticket_id`),
  CONSTRAINT `fk_hist_ticket` FOREIGN KEY (`ticket_id`) REFERENCES `tickets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ticket_historial` (`ticket_id`,`autor`,`tipo`,`mensaje`,`creado_en`) VALUES
(1,'Sistema','creacion','Ticket creado automáticamente desde el Portal Ciudadano.','2026-10-13 09:12:00'),
(1,'María González','asignacion','Asignado a Carlos Muñoz','2026-10-14 11:49:44'),
(1,'Carlos Muñoz','nota_interna','El perfil de RRHH quedó desincronizado tras el cambio de dominio. Verificar con el proveedor AD antes de responder.','2026-10-14 12:05:00'),
(1,'Carlos Muñoz','respuesta','Estimado Juan: restablecimos la sincronización de su cuenta. Por favor intente ingresar nuevamente y confirme si el acceso funciona.','2026-10-14 12:20:00'),
(2,'Sistema','creacion','Ticket creado automáticamente desde el Portal Ciudadano.','2026-10-13 11:40:00'),
(2,'Carlos Muñoz','respuesta','Su solicitud de restablecimiento fue procesada. Recibirá un correo con el enlace de cambio de clave en los próximos 15 minutos.','2026-10-14 09:30:00'),
(3,'Sistema','creacion','Ticket creado automáticamente desde el Portal Ciudadano.','2026-10-13 08:05:00'),
(3,'Supervisor de Mesa','estado','Prioridad elevada a URGENTE. Ticket escalado al equipo de Redes.','2026-10-13 09:00:00'),
(3,'Carlos Muñoz','nota_interna','Caso derivado al proveedor de conectividad (ticket externo OPS-4471). Esperar respuesta antes de escalar a jefatura.','2026-10-14 10:15:00'),
(4,'Carlos Muñoz','respuesta','Bajas de cuentas realizadas y confirmadas por jefatura de personal.','2026-10-08 17:00:00'),
(4,'Sistema','estado','Estado cambiado a Resuelto.','2026-10-08 17:05:00'),
(5,'Carlos Muñoz','respuesta','Se reemplazó el cable HDMI y se actualizaron los drivers. El equipo quedó operativo.','2026-10-06 10:00:00'),
(5,'Sistema','estado','Estado cambiado a Cerrado.','2026-10-07 09:00:00'),
(6,'Supervisor de Mesa','respuesta','Se aisló el correo malicioso y se envió alerta general al personal. Quedamos atentos a sus comentarios.','2026-10-13 18:10:00'),
(7,'Administrador General','creacion','Ticket interno creado desde el Panel de Administración.','2026-10-14 10:05:00'),
(8,'Supervisor de Mesa','creacion','Ticket interno creado desde el Panel de Administración.','2026-10-14 11:30:00');

SET FOREIGN_KEY_CHECKS = 1;

-- FIN DEL SCRIPT
