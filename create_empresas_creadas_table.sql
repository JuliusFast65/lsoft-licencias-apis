-- ----------------------------------------------------------------------------
-- Script SQL para crear tabla Empresas_Creadas
-- Esta tabla rastrea las empresas/bases de datos que utilizan el sistema ERP.
-- Puede incluir tanto la propia empresa dueña de la licencia como empresas
-- creadas por ella. La distinción clave es:
-- - Tabla Empresas: empresas dueñas de licencias
-- - Tabla Empresas_Creadas: empresas/bases de datos que se utilizan (transacciones)
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `Empresas_Creadas` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `RUC_Creado` VARCHAR(13) NOT NULL COMMENT 'RUC de la empresa/base de datos utilizada (máximo 13 dígitos). Puede ser igual a RUC si es la propia empresa dueña.',
  `RUC` VARCHAR(13) NOT NULL COMMENT 'RUC de la empresa dueña de la licencia (máximo 13 dígitos)',
  `Serie` VARCHAR(50) NOT NULL COMMENT 'Serie de la licencia utilizada',
  `Sistema` VARCHAR(10) DEFAULT NULL COMMENT 'Sistema usado más recientemente: LSOFT, FSOFT, LSOFTW (información de rastreo)',
  `Nombre_Empresa` VARCHAR(255) DEFAULT NULL COMMENT 'Nombre de la empresa creada',
  `Fecha_Creacion` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha en que se registró por primera vez',
  `Ultimo_Acceso` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Último acceso a esta empresa (desde cualquier sistema)',
  `IP` VARCHAR(45) DEFAULT NULL COMMENT 'IP desde la que se accedió',
  `Maquina` VARCHAR(100) DEFAULT NULL COMMENT 'Máquina desde la que se accedió',
  `Activa` TINYINT(1) DEFAULT 1 COMMENT '1=Activa, 0=Inactiva',
  `Fecha_Ultimo_Respaldo_Nube` DATETIME DEFAULT NULL COMMENT 'Fecha del último respaldo a la nube/servidor',
  `Fecha_Ultimo_Respaldo_Otra_Unidad` DATETIME DEFAULT NULL COMMENT 'Fecha del último respaldo a otra unidad/carpeta',
  `Contador_Respaldos_Nube` INT(11) DEFAULT 0 COMMENT 'Contador de respaldos realizados a la nube',
  `Contador_Respaldos_Otra_Unidad` INT(11) DEFAULT 0 COMMENT 'Contador de respaldos realizados a otra unidad',
  `Usuario_Respaldo_Nube` VARCHAR(100) DEFAULT NULL COMMENT 'Usuario del login del ERP que realizó el último respaldo a la nube',
  `Ubicacion_Respaldo_Otra_Unidad` VARCHAR(500) DEFAULT NULL COMMENT 'Ruta/ubicación del último respaldo en otra unidad',
  `Usuario_Respaldo_Otra_Unidad` VARCHAR(100) DEFAULT NULL COMMENT 'Usuario del login del ERP que realizó el último respaldo a otra unidad',
  `Fecha_Ultimo_Respaldo_BD_General_Nube` DATETIME DEFAULT NULL COMMENT 'Fecha del último respaldo de BD general a la nube/servidor',
  `Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad` DATETIME DEFAULT NULL COMMENT 'Fecha del último respaldo de BD general a otra unidad/carpeta',
  `Contador_Respaldos_BD_General_Nube` INT(11) DEFAULT 0 COMMENT 'Contador de respaldos de BD general realizados a la nube',
  `Contador_Respaldos_BD_General_Otra_Unidad` INT(11) DEFAULT 0 COMMENT 'Contador de respaldos de BD general realizados a otra unidad',
  `Usuario_Respaldo_BD_General_Nube` VARCHAR(100) DEFAULT NULL COMMENT 'Usuario del login del ERP que realizó el último respaldo de BD general a la nube',
  `Ubicacion_Respaldo_BD_General_Otra_Unidad` VARCHAR(500) DEFAULT NULL COMMENT 'Ruta/ubicación del último respaldo de BD general en otra unidad',
  `Usuario_Respaldo_BD_General_Otra_Unidad` VARCHAR(100) DEFAULT NULL COMMENT 'Usuario del login del ERP que realizó el último respaldo de BD general a otra unidad',
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ruc_ruc_creado` (`RUC`, `RUC_Creado`),
  KEY `idx_ruc` (`RUC`),
  KEY `idx_ruc_creado` (`RUC_Creado`),
  KEY `idx_serie` (`Serie`),
  KEY `idx_sistema` (`Sistema`),
  KEY `idx_ultimo_acceso` (`Ultimo_Acceso`),
  KEY `idx_fecha_respaldo_nube` (`Fecha_Ultimo_Respaldo_Nube`),
  KEY `idx_fecha_respaldo_otra_unidad` (`Fecha_Ultimo_Respaldo_Otra_Unidad`),
  KEY `idx_fecha_respaldo_bd_general_nube` (`Fecha_Ultimo_Respaldo_BD_General_Nube`),
  KEY `idx_fecha_respaldo_bd_general_otra_unidad` (`Fecha_Ultimo_Respaldo_BD_General_Otra_Unidad`)
)
ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci
COMMENT='Empresas/bases de datos utilizadas en el sistema. Un solo registro por empresa/base de datos, independiente del sistema (LSOFT/FSOFT/LSOFTW). RUC se refiere a la empresa dueña de la licencia.';

