<?php
/**
 * schema_v3_embebido.php — Sentencias de la migración v3 como array PHP,
 * para que install.php funcione incluso sin el archivo schema_v3.sql.
 * (Las FK se omiten a propósito: no son esenciales y evitan fallos en BD mixtas.)
 */
function schema_v3_sentencias(): array
{
    return [
    "CREATE TABLE IF NOT EXISTS `kb_articulos` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `titulo` VARCHAR(200) NOT NULL,
      `resumen` VARCHAR(500) DEFAULT NULL,
      `contenido` MEDIUMTEXT NOT NULL,
      `categoria_id` INT UNSIGNED DEFAULT NULL,
      `estado` ENUM('borrador','publicado') NOT NULL DEFAULT 'borrador',
      `visibilidad` ENUM('externa','interna') NOT NULL DEFAULT 'externa',
      `destacado` TINYINT(1) NOT NULL DEFAULT 0,
      `etiquetas` VARCHAR(300) DEFAULT NULL,
      `palabras_clave` VARCHAR(300) DEFAULT NULL,
      `vistas` INT UNSIGNED NOT NULL DEFAULT 0,
      `creado_por` INT UNSIGNED DEFAULT NULL,
      `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `idx_kb_estado` (`estado`), KEY `idx_kb_vis` (`visibilidad`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "CREATE TABLE IF NOT EXISTS `configuracion` (
      `clave` VARCHAR(60) NOT NULL, `valor` TEXT DEFAULT NULL, PRIMARY KEY (`clave`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "INSERT IGNORE INTO `configuracion` (`clave`,`valor`) VALUES
     ('nombre_sistema','Helpdesk Institucional'),
     ('nombre_institucion','Gobierno de Chile · Ministerio Secretaría General'),
     ('logo','🖥️'),('color_primario','#0039A6'),
     ('correo_contacto','soporte@helpdesk.gob.cl'),('telefono_contacto','+56 2 2708 1000'),
     ('zona_horaria','America/Santiago'),('max_upload_mb','10'),
     ('smtp_activo','0'),('smtp_host',''),('smtp_puerto','587'),
     ('smtp_usuario',''),('smtp_password',''),('smtp_remitente','mesadeayuda@helpdesk.gob.cl')",

    "ALTER TABLE `categorias` ADD COLUMN IF NOT EXISTS `visibilidad` ENUM('both','internal','external') NOT NULL DEFAULT 'both'",
    "ALTER TABLE `usuarios` ADD COLUMN IF NOT EXISTS `ultimo_acceso` DATETIME NULL DEFAULT NULL",

    "CREATE TABLE IF NOT EXISTS `departamentos` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `nombre` VARCHAR(120) NOT NULL UNIQUE,
      `activo` TINYINT(1) NOT NULL DEFAULT 1, `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "INSERT IGNORE INTO `departamentos` (`nombre`) VALUES
     ('Dirección de Planificación'),('Dirección de Finanzas'),('Dirección de RRHH'),
     ('Dirección de Operaciones'),('Unidad de Personas'),('Secretaría Regional Ministerial'),
     ('Oficina de Partes'),('Unidad de Auditoría')",

    "CREATE TABLE IF NOT EXISTS `ubicaciones` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `nombre` VARCHAR(120) NOT NULL UNIQUE,
      `direccion` VARCHAR(200) DEFAULT NULL, `activo` TINYINT(1) NOT NULL DEFAULT 1,
      `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "INSERT IGNORE INTO `ubicaciones` (`nombre`,`direccion`) VALUES
     ('Edificio Central — Piso 1','Alameda 123, Santiago'),
     ('Edificio Central — Piso 2','Alameda 123, Santiago'),
     ('Edificio Central — Piso 3','Alameda 123, Santiago'),
     ('Anexo A','Av. Providencia 456, Santiago'),
     ('Anexo B','Av. Providencia 456, Santiago — Subterráneo'),
     ('Remoto / Teletrabajo','—')",

    "CREATE TABLE IF NOT EXISTS `equipos_soporte` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `nombre` VARCHAR(120) NOT NULL UNIQUE,
      `descripcion` VARCHAR(255) DEFAULT NULL, `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "INSERT IGNORE INTO `equipos_soporte` (`nombre`,`descripcion`) VALUES
     ('Equipo de Soporte Nivel 1','Atención inicial: cuentas, correo y periféricos.'),
     ('Equipo de Redes e Infraestructura','Conectividad, VPN, servidores y seguridad.')",

    "CREATE TABLE IF NOT EXISTS `equipo_miembros` (
      `equipo_id` INT UNSIGNED NOT NULL, `usuario_id` INT UNSIGNED NOT NULL, PRIMARY KEY (`equipo_id`,`usuario_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "INSERT IGNORE INTO `equipo_miembros` (`equipo_id`,`usuario_id`)
     SELECT e.id, u.id FROM `equipos_soporte` e JOIN `usuarios` u ON u.usuario IN ('tecnico','supervisor')
     WHERE e.nombre = 'Equipo de Soporte Nivel 1'",

    "CREATE TABLE IF NOT EXISTS `tipos_ticket` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `nombre` VARCHAR(80) NOT NULL UNIQUE,
      `descripcion` VARCHAR(255) DEFAULT NULL, `activo` TINYINT(1) NOT NULL DEFAULT 1, PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    "INSERT IGNORE INTO `tipos_ticket` (`nombre`,`descripcion`) VALUES
     ('Incidencia','Interrupción o degradación de un servicio o equipo.'),
     ('Solicitud de servicio','Petición estándar: alturas, permisos, insumos.'),
     ('Consulta','Pregunta o requerimiento de información técnica.'),
     ('Requerimiento','Desarrollo o cambio en sistemas internos.'),
     ('Mantención programada','Trabajos planificados sobre infraestructura.'),
     ('Solicitud de acceso','Altas, bajas y restablecimientos de cuentas.')",

    "CREATE TABLE IF NOT EXISTS `auditoria` (
      `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `usuario_id` INT UNSIGNED DEFAULT NULL,
      `usuario` VARCHAR(120) NOT NULL, `accion` VARCHAR(80) NOT NULL, `detalle` VARCHAR(500) DEFAULT NULL,
      `ip` VARCHAR(45) DEFAULT NULL, `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`), KEY `idx_aud_fecha` (`creado_en`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // Artículos semilla de la Base de Conocimiento
    "INSERT INTO `kb_articulos` (`titulo`,`resumen`,`contenido`,`categoria_id`,`estado`,`visibilidad`,`destacado`,`etiquetas`,`palabras_clave`,`vistas`,`creado_por`)
     SELECT 'Cómo restablecer su contraseña desde el portal de autoservicio',
            'Siga estos 4 pasos para recuperar el acceso a su cuenta institucional sin abrir un ticket.',
            '<p>1. Ingrese a <strong>Mi Cuenta &gt; Seguridad</strong>.</p><p>2. Pulse <em>&quot;Restablecer contraseña&quot;</em> y responda la pregunta de seguridad.</p><p>3. Revise su correo alterno y use el enlace (válido 30 minutos).</p><p>4. Defina una clave de al menos 12 caracteres con mayúsculas, números y símbolos.</p>',
            2,'publicado','externa',1,'contraseña,acceso,autoservicio','restablecer clave',341,
            (SELECT id FROM usuarios WHERE usuario='admin' LIMIT 1)
     FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM kb_articulos WHERE titulo='Cómo restablecer su contraseña desde el portal de autoservicio')",

    "INSERT INTO `kb_articulos` (`titulo`,`resumen`,`contenido`,`categoria_id`,`estado`,`visibilidad`,`destacado`,`etiquetas`,`palabras_clave`,`vistas`,`creado_por`)
     SELECT 'VPN institucional cae cada pocos minutos: guía de diagnóstico',
            'Checklist nivel 1 para reconexiones intermitentes del cliente VPN.',
            '<p><strong>Síntoma:</strong> desconexiones cíclicas (&lt;15 min).</p><ol><li>Verificar hora del equipo (desvío &gt; 2 min rompe el túnel).</li><li>Ejecutar <code>tracert vpn.gob.cl</code> y adjuntar salida.</li><li>Renovar arrendamiento DHCP del adaptador virtual.</li><li>Si persiste, escalar a Redes con log de la carpeta de traces.</li></ol>',
            1,'publicado','interna',0,'vpn,redes,diagnóstico','vpn desconexión',58,
            (SELECT id FROM usuarios WHERE usuario='admin' LIMIT 1)
     FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM kb_articulos WHERE titulo='VPN institucional cae cada pocos minutos: guía de diagnóstico')",

    "INSERT INTO `kb_articulos` (`titulo`,`resumen`,`contenido`,`categoria_id`,`estado`,`visibilidad`,`destacado`,`etiquetas`,`palabras_clave`,`vistas`,`creado_por`)
     SELECT 'Reporte de correos de phishing: procedimiento ciudadano',
            'Qué hacer si recibe un correo sospechoso que suplanta entidades gubernamentales.',
            '<p>No responda ni haga clic en enlaces.</p><p>Use el botón <strong>&quot;Reportar Phishing&quot;</strong> de Outlook o reenvíe a <code>seguridad@helpdesk.gob.cl</code> como adjunto.</p><p>Si ingresó su clave, cambie la contraseña de inmediato y abra un ticket de Seguridad Informática.</p>',
            5,'publicado','externa',0,'phishing,seguridad','correo falso',212,
            (SELECT id FROM usuarios WHERE usuario='admin' LIMIT 1)
     FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM kb_articulos WHERE titulo='Reporte de correos de phishing: procedimiento ciudadano')",

    "INSERT INTO `kb_articulos` (`titulo`,`resumen`,`contenido`,`categoria_id`,`estado`,`visibilidad`,`destacado`,`etiquetas`,`palabras_clave`,`vistas`,`creado_por`)
     SELECT 'Protocolo interno: escalamiento de incidentes críticos',
            'Matriz de escalamiento N1 → N2 → Jefatura con tiempos máximos por prioridad.',
            '<p><strong>Urgente:</strong> N1 tiene 15 min para escalar a Redes/N2; se notifica a jefatura dentro de 30 min.</p><p><strong>Alta:</strong> escalamiento máximo a las 2 h.</p><p>Toda escalación debe dejar nota interna con: evidencia, acciones realizadas y ticket externo del proveedor.</p>',
            3,'publicado','interna',1,'escalamiento,SLA,protocolo','matriz escalamiento',27,
            (SELECT id FROM usuarios WHERE usuario='admin' LIMIT 1)
     FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM kb_articulos WHERE titulo='Protocolo interno: escalamiento de incidentes críticos')",
    ];
}
