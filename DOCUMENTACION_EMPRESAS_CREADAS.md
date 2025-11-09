# Documentación: Sistema de Seguimiento de Empresas Creadas

## Resumen

Este sistema permite rastrear las empresas (RUCS/bases de datos) que utilizan el sistema ERP. La distinción clave es:

- **Tabla `Empresas`**: Contiene las empresas **dueñas de licencias** (quienes tienen la licencia registrada)
- **Tabla `Empresas_Creadas`**: Contiene las empresas/bases de datos que **utilizan el sistema**, que pueden ser:
  - La propia empresa dueña de la licencia (cuando usa su propia base de datos)
  - Empresas creadas por la empresa dueña (cuando crea empresas adicionales)
  - Tanto para sistemas de escritorio (LSOFT/FSOFT) como para sistema web (LSOFTW)

El objetivo es poder informar a los clientes cuando no han realizado respaldos de estas empresas/bases de datos, independientemente de si es la empresa dueña o una empresa creada, y del sistema utilizado (escritorio o web).

**Nota importante**: Una empresa/base de datos tiene **un solo registro** en `Empresas_Creadas`, independiente del sistema utilizado (LSOFT, FSOFT, LSOFTW). El campo `Sistema` es informativo y se actualiza al sistema más reciente desde el que se accedió. Esto permite contar correctamente cuántas empresas/bases de datos están usando el sistema y tener un solo control de respaldos por empresa.

## Arquitectura

### Flujo de Trabajo

#### ERP de Escritorio (LSOFT/FSOFT)

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Usuario inicia el ERP                                    │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Validar_Licencia (ANTES del login)                       │
│    - RUC: Empresa dueña de la licencia                      │
│    - Serie: Serie de la licencia                            │
│    - Sistema: LSOFT/FSOFT                                   │
│    → Validación de licencia exitosa                         │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. Usuario hace login                                        │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. Usuario selecciona una empresa/base de datos             │
│    (puede ser la empresa dueña o una empresa creada)        │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. Registrar_Empresa_Creada (DESPUÉS del login)            │
│    - RUC: Empresa dueña de la licencia                      │
│    - RUC_Creado: Empresa/base de datos seleccionada         │
│                  (puede ser igual a RUC si es la            │
│                   propia empresa dueña)                     │
│    - Serie: Serie de la licencia                            │
│    - Sistema: LSOFT/FSOFT                                   │
│    - Nombre_Empresa: Nombre de la empresa (opcional)        │
│    → Registro/Actualización de acceso                       │
└─────────────────┬───────────────────────────────────────────┘
                  │
                  ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. Usuario trabaja con la empresa/base de datos             │
└─────────────────────────────────────────────────────────────┘
```

**Puntos clave**:
1. `Validar_Licencia` se llama ANTES del login, siempre con el RUC de la empresa dueña.
2. `Registrar_Empresa_Creada` se llama DESPUÉS del login, cuando el usuario selecciona una empresa/base de datos para trabajar.
3. **Importante**: Incluso si el usuario selecciona la propia empresa dueña (RUC_Creado = RUC), debe registrarse en `Empresas_Creadas` para rastrear accesos y respaldos.
4. Si el usuario cambia de empresa/base de datos, se debe volver a llamar a `Registrar_Empresa_Creada`.

#### ERP Web (LSOFTW)

- El endpoint `Registrar_Sesion` siempre recibe el RUC de la empresa dueña de la licencia para el control de sesiones.
- **Registro de empresas creadas**: Cuando se usa `Registrar_Sesion` con licencias LSOFTW, se puede proporcionar el parámetro opcional `RUC_Empresa_Creada` para registrar la empresa/base de datos que se está utilizando.
  - Si se proporciona `RUC_Empresa_Creada`: Se registra esa empresa en `Empresas_Creadas` con `RUC_Creado = RUC_Empresa_Creada` y `Sistema = 'LSOFTW'`.
  - Si NO se proporciona: Se registra la empresa dueña con `RUC_Creado = RUC` y `Sistema = 'LSOFTW'`.
- **Ventaja**: LSOFTW puede registrar empresas creadas en una sola llamada a `Registrar_Sesion`, sin necesidad de llamar a `Registrar_Empresa_Creada` por separado.
- Esto permite rastrear accesos y respaldos tanto de la empresa dueña como de empresas creadas cuando usan la versión web.

## Tabla de Base de Datos

### Empresas_Creadas

```sql
CREATE TABLE Empresas_Creadas (
  id INT(11) AUTO_INCREMENT PRIMARY KEY,
  RUC_Creado VARCHAR(13) NOT NULL,           -- RUC de la empresa/base de datos utilizada (máximo 13 dígitos). Puede ser igual a RUC si es la propia empresa dueña.
  RUC VARCHAR(13) NOT NULL,                  -- RUC de la empresa dueña de la licencia (máximo 13 dígitos)
  Serie VARCHAR(50) NOT NULL,                -- Serie de la licencia
  Sistema VARCHAR(10) DEFAULT NULL,          -- Sistema usado más recientemente: LSOFT, FSOFT, LSOFTW (campo informativo, no parte de la clave única)
  Nombre_Empresa VARCHAR(255),               -- Nombre de la empresa creada
  Fecha_Creacion DATETIME,                   -- Primera vez que se registró
  Ultimo_Acceso DATETIME,                    -- Último acceso a esta empresa (desde cualquier sistema)
  IP VARCHAR(45),                            -- IP del último acceso
  Maquina VARCHAR(100),                      -- Máquina del último acceso
  Activa TINYINT(1) DEFAULT 1,               -- 1=Activa, 0=Inactiva
  Fecha_Ultimo_Respaldo_Nube DATETIME,       -- Fecha del último respaldo a la nube/servidor
  Fecha_Ultimo_Respaldo_Otra_Unidad DATETIME, -- Fecha del último respaldo a otra unidad/carpeta
  Contador_Respaldos_Nube INT DEFAULT 0,    -- Contador de respaldos realizados a la nube
  Contador_Respaldos_Otra_Unidad INT DEFAULT 0, -- Contador de respaldos realizados a otra unidad
  Usuario_Respaldo_Nube VARCHAR(100),        -- Usuario del login del ERP que realizó el último respaldo a la nube
  Ubicacion_Respaldo_Otra_Unidad VARCHAR(500), -- Ruta/ubicación del último respaldo en otra unidad
  Usuario_Respaldo_Otra_Unidad VARCHAR(100),  -- Usuario del login del ERP que realizó el último respaldo a otra unidad
  PRIMARY KEY (id),
  UNIQUE KEY idx_ruc_creado (RUC, RUC_Creado)  -- Clave única: un solo registro por empresa/base de datos
);
```

**Importante**: La clave única es `(RUC, RUC_Creado)`, **sin Sistema**. Esto significa que:
- Una empresa/base de datos tiene un solo registro, independiente del sistema utilizado
- Si una empresa usa la misma base de datos en LSOFT y LSOFTW, se actualiza el mismo registro
- El campo `Sistema` se actualiza al sistema más reciente desde el que se accedió
- Esto permite contar correctamente cuántas empresas están usando el sistema y tener un solo control de respaldos

## Endpoints

### 1. Validar_Licencia

**Endpoint**: `Validar_Licencia.php`

**Nota importante**: Este endpoint se llama ANTES del login, siempre con el RUC de la empresa dueña de la licencia. No se usa para registrar empresas creadas, ya que en ese momento aún no se sabe qué empresa/base de datos utilizará el usuario.

**Uso**: Sin cambios. Se mantiene exactamente como estaba antes.

---

### 1.1. Registrar_Sesion (LSOFTW)

**Endpoint**: `Registrar_Sesion.php`

**Nota importante**: Este endpoint es usado por LSOFTW (ERP Web) y también por LSOFT/FSOFT cuando usan licenciamiento por sesión. Siempre recibe el RUC de la empresa dueña de la licencia para el control de sesiones.

**Parámetros opcionales para LSOFTW**:
- `RUC_Empresa_Creada`: RUC de la empresa/base de datos que se está utilizando (opcional). Si no se proporciona, se asume que se está usando la empresa dueña.
- `Nombre_Empresa_Creada`: Nombre de la empresa creada (opcional). Si no se proporciona, se intenta obtener de la tabla `Empresas`.

**Comportamiento**:
- Si se procesan licencias LSOFTW, se registra/actualiza en `Empresas_Creadas` con:
  - `RUC`: RUC de la empresa dueña de la licencia (siempre el RUC recibido)
  - `RUC_Creado`: RUC proporcionado en `RUC_Empresa_Creada` o, si no se proporciona, el RUC dueña (`RUC_Creado = RUC`)
  - `Sistema`: 'LSOFTW' (se actualiza al sistema más reciente)
  - `Serie`: Serie de la licencia (generada o existente)
  - `Nombre_Empresa`: Nombre proporcionado en `Nombre_Empresa_Creada` o obtenido de la tabla `Empresas`
- **Importante**: Si la misma empresa/base de datos ya existe (por ejemplo, registrada desde LSOFT), se actualiza el mismo registro, cambiando `Sistema` a 'LSOFTW' y actualizando `Ultimo_Acceso`.
- **Ventaja**: LSOFTW puede registrar empresas creadas en una sola llamada, sin necesidad de llamar a `Registrar_Empresa_Creada` por separado.

---

### 2. Registrar_Empresa_Creada

**Endpoint**: `Registrar_Empresa_Creada.php`

**Propósito**: Registrar o actualizar el acceso a una empresa/base de datos utilizada en el sistema. Puede ser la propia empresa dueña de la licencia o una empresa creada por ella.

**Parámetros requeridos**:
- `RUC`: RUC de la empresa dueña de la licencia (máximo 13 caracteres)
- `RUC_Creado`: RUC de la empresa/base de datos utilizada (máximo 13 caracteres)
- `Serie`: Serie de la licencia

**Parámetros opcionales**:
- `Sistema`: Sistema utilizado (LSOFT, FSOFT, LSOFTW). Campo informativo, no afecta la unicidad del registro.

**Validaciones**:
- Los RUCs se validan para que no excedan 13 caracteres.
- **Nota importante**: Es válido que `RUC_Creado` sea igual a `RUC`. Esto ocurre cuando la empresa dueña de la licencia usa su propia base de datos.
- **Unicidad**: Solo puede haber un registro por combinación `(RUC, RUC_Creado)`, independiente del sistema. Si la misma empresa/base de datos se usa en LSOFT y LSOFTW, se actualiza el mismo registro.

**Parámetros opcionales**:
- `Nombre_Empresa`: Nombre de la empresa creada
- `Maquina`: Identificador de la máquina

**Ejemplo de petición**:
```json
{
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567002",
  "Serie": "ABC123456",
  "Sistema": "LSOFT",
  "Nombre_Empresa": "Empresa Filial S.A.",
  "Maquina": "PC-OFICINA-01"
}
```

**Respuesta exitosa**:
```json
{
  "Fin": "OK",
  "Mensaje": "Empresa creada registrada exitosamente",
  "id": 1,
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567002",
  "Sistema": "LSOFT",
  "Ultimo_Acceso": "2025-01-20 10:30:00"
}
```

**Comportamiento**:
- Si la empresa creada ya existe, actualiza el último acceso y otros campos.
- Si no existe, crea un nuevo registro.
- Valida que la empresa dueña exista en la tabla `Empresas`.

---

### 3. Consultar_Empresas_Creadas

**Endpoint**: `Consultar_Empresas_Creadas.php`

**Propósito**: Consultar las empresas/bases de datos utilizadas por una empresa dueña de licencia. Incluye tanto la propia empresa dueña como las empresas creadas por ella.

**Parámetros requeridos**:
- `RUC`: RUC de la empresa dueña de la licencia (máximo 13 caracteres)

**Parámetros opcionales**:
- `Sistema`: Filtrar por sistema (LSOFT, FSOFT, LSOFTW)
- `Solo_Activas`: Boolean, por defecto `true`
- `Dias_Sin_Acceso`: Filtrar empresas sin acceso por X días

**Ejemplo de petición**:
```json
{
  "RUC": "0991234567001",
  "Sistema": "LSOFT",
  "Solo_Activas": true,
  "Dias_Sin_Acceso": 30
}
```

**Respuesta exitosa**:
```json
{
  "Fin": "OK",
  "RUC": "0991234567001",
  "Nombre_Empresa_Dueno": "Empresa Principal S.A.",
  "Total_Empresas": 5,
  "Empresas_Activas": 4,
  "Sin_Acceso_30_Dias": 2,
  "Sin_Respaldo_7_Dias": 1,
  "Empresas_Creadas": [
    {
      "id": 1,
      "RUC_Creado": "0991234567002",
      "RUC": "0991234567001",
      "Serie": "ABC123456",
      "Sistema": "LSOFT",
      "Nombre_Empresa": "Empresa Filial S.A.",
      "Fecha_Creacion": "2024-01-15 08:00:00",
      "Ultimo_Acceso": "2025-01-20 10:30:00",
      "IP": "192.168.1.100",
      "Maquina": "PC-OFICINA-01",
      "Activa": true,
      "Fecha_Ultimo_Respaldo": "2025-01-18 20:00:00",
      "Dias_Sin_Acceso": 0,
      "Dias_Sin_Respaldo": 2
    }
  ]
}
```

---

### 4. Actualizar_Respaldo_Empresa_Creada

**Endpoint**: `Actualizar_Respaldo_Empresa_Creada.php`

**Propósito**: Actualizar información de respaldos de una empresa/base de datos. Soporta respaldos a la nube/servidor y respaldos a otra unidad/carpeta. Aplica tanto a la empresa dueña como a empresas creadas.

**Parámetros requeridos**:
- `RUC`: RUC de la empresa dueña de la licencia (máximo 13 caracteres)
- `RUC_Creado`: RUC de la empresa/base de datos utilizada (máximo 13 caracteres)

**Parámetros opcionales para respaldo a nube**:
- `Fecha_Respaldo_Nube`: Fecha del respaldo a la nube (formato YYYY-MM-DD HH:MM:SS). Si se proporciona, actualiza `Fecha_Ultimo_Respaldo_Nube` e incrementa `Contador_Respaldos_Nube`.
- `Usuario_Respaldo_Nube`: Usuario del login del ERP que realizó el respaldo a la nube (máximo 100 caracteres).

**Parámetros opcionales para respaldo a otra unidad**:
- `Fecha_Respaldo_Otra_Unidad`: Fecha del respaldo a otra unidad (formato YYYY-MM-DD HH:MM:SS). Si se proporciona, actualiza `Fecha_Ultimo_Respaldo_Otra_Unidad` e incrementa `Contador_Respaldos_Otra_Unidad`.
- `Ubicacion_Respaldo_Otra_Unidad`: Ruta/ubicación del respaldo en otra unidad (máximo 500 caracteres).
- `Usuario_Respaldo_Otra_Unidad`: Usuario del login del ERP que realizó el respaldo a otra unidad (máximo 100 caracteres).

**Nota**: Debe proporcionarse al menos `Fecha_Respaldo_Nube` o `Fecha_Respaldo_Otra_Unidad`. Los respaldos a nube y otra unidad son independientes y pueden actualizarse por separado o en la misma llamada.

**Ejemplo 1: Respaldo a nube**:
```json
{
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567002",
  "Fecha_Respaldo_Nube": "2025-01-20 20:00:00",
  "Usuario_Respaldo_Nube": "admin"
}
```

**Ejemplo 2: Respaldo a otra unidad**:
```json
{
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567002",
  "Fecha_Respaldo_Otra_Unidad": "2025-01-20 21:00:00",
  "Ubicacion_Respaldo_Otra_Unidad": "D:\\Respaldos\\Empresa_20250120.bak",
  "Usuario_Respaldo_Otra_Unidad": "usuario1"
}
```

**Ejemplo 3: Ambos respaldos en la misma llamada**:
```json
{
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567002",
  "Fecha_Respaldo_Nube": "2025-01-20 20:00:00",
  "Usuario_Respaldo_Nube": "admin",
  "Fecha_Respaldo_Otra_Unidad": "2025-01-20 21:00:00",
  "Ubicacion_Respaldo_Otra_Unidad": "D:\\Respaldos\\Empresa_20250120.bak",
  "Usuario_Respaldo_Otra_Unidad": "usuario1"
}
```

**Respuesta exitosa**:
```json
{
  "Fin": "OK",
  "Mensaje": "Información de respaldo actualizada exitosamente",
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567002",
  "Respaldo_Nube": {
    "Fecha_Ultimo_Respaldo_Nube": "2025-01-20 20:00:00",
    "Contador_Respaldos_Nube": 15,
    "Usuario_Respaldo_Nube": "admin"
  },
  "Respaldo_Otra_Unidad": {
    "Fecha_Ultimo_Respaldo_Otra_Unidad": "2025-01-20 21:00:00",
    "Contador_Respaldos_Otra_Unidad": 8,
    "Ubicacion_Respaldo_Otra_Unidad": "D:\\Respaldos\\Empresa_20250120.bak",
    "Usuario_Respaldo_Otra_Unidad": "usuario1"
  }
}
```

**Uso recomendado**:
- Este endpoint debe ser llamado automáticamente por el proceso de respaldo del ERP.
- Para respaldos a la nube: proporcionar `Fecha_Respaldo_Nube` y opcionalmente `Usuario_Respaldo_Nube`.
- Para respaldos a otra unidad: proporcionar `Fecha_Respaldo_Otra_Unidad` y opcionalmente `Ubicacion_Respaldo_Otra_Unidad` y `Usuario_Respaldo_Otra_Unidad`.
- Si el proceso de respaldo está apagado o falla, no se actualizará la fecha correspondiente, permitiendo detectar empresas sin respaldo reciente.

---

## Implementación en el ERP de Escritorio

### Flujo de Implementación

1. **Al cargar el ERP (antes del login)**: 
   - Llamar a `Validar_Licencia` con el RUC de la empresa dueña de la licencia.
   - Este paso NO cambia, se mantiene igual que siempre.

2. **Después del login, cuando el usuario selecciona una empresa/base de datos**:
   - El ERP debe llamar a `Registrar_Empresa_Creada` para registrar/actualizar el acceso.
   - **Importante**: Incluso si selecciona la propia empresa dueña, debe registrarse.
   - `RUC_Creado` puede ser igual a `RUC` cuando la empresa dueña usa su propia base.

3. **Cuando el usuario cambia de empresa/base de datos**:
   - Volver a llamar a `Registrar_Empresa_Creada` con la nueva empresa/base de datos seleccionada.

### Ejemplo de Implementación

Cuando el usuario selecciona una empresa/base de datos, hacer una llamada a `Registrar_Empresa_Creada`:

**Caso 1: Empresa dueña usa su propia base de datos**
```json
{
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567001",  // Igual al RUC
  "Serie": "ABC123456",
  "Sistema": "LSOFT",  // Opcional, informativo
  "Nombre_Empresa": "Empresa Principal S.A."
}
```

**Caso 2: Empresa dueña usa una empresa creada**
```json
{
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567002",  // Diferente al RUC
  "Serie": "ABC123456",
  "Sistema": "LSOFT",  // Opcional, informativo
  "Nombre_Empresa": "Filial S.A."
}
```

**Nota importante**: Si la misma empresa/base de datos se usa tanto en LSOFT como en LSOFTW, se actualiza el mismo registro. El campo `Sistema` se actualiza al sistema más reciente desde el que se accedió.

## Implementación en el ERP Web (LSOFTW)

### Flujo de Implementación

1. **Al iniciar sesión**: 
   - Llamar a `Registrar_Sesion` con el RUC de la empresa dueña de la licencia para el control de sesiones.
   - Si el usuario está usando la empresa dueña: No proporcionar `RUC_Empresa_Creada`. Se registrará automáticamente la empresa dueña.
   - Si el usuario está usando una empresa creada: Proporcionar `RUC_Empresa_Creada` y opcionalmente `Nombre_Empresa_Creada`.

2. **Cuando el usuario cambia de empresa/base de datos**:
   - Volver a llamar a `Registrar_Sesion` con los parámetros correspondientes:
     - Si cambia a la empresa dueña: No proporcionar `RUC_Empresa_Creada`.
     - Si cambia a una empresa creada: Proporcionar `RUC_Empresa_Creada` y `Nombre_Empresa_Creada`.

### Ejemplo de Implementación para LSOFTW

**Caso 1: Usuario usa la empresa dueña**
```json
{
  "RUC": "0991234567001",
  "usuario": "admin",
  "licencias": ["LSOFTW_BA"],
  "Serie": null,
  "info_nav": "Chrome/..."
}
```
→ Se registra automáticamente en `Empresas_Creadas` con `RUC_Creado = RUC`.

**Caso 2: Usuario usa una empresa creada**
```json
{
  "RUC": "0991234567001",
  "RUC_Empresa_Creada": "0991234567002",
  "Nombre_Empresa_Creada": "Filial S.A.",
  "usuario": "admin",
  "licencias": ["LSOFTW_BA"],
  "Serie": null,
  "info_nav": "Chrome/..."
}
```
→ Se registra en `Empresas_Creadas` con `RUC_Creado = 0991234567002` y `Sistema = 'LSOFTW'`.

**Nota importante**: Si la misma empresa/base de datos ya existe (por ejemplo, registrada desde LSOFT de escritorio), se actualiza el mismo registro, cambiando `Sistema` a 'LSOFTW' y actualizando `Ultimo_Acceso`. No se crea un registro duplicado.

---

### Proceso de Respaldo

El ERP debe llamar a `Actualizar_Respaldo_Empresa_Creada` después de realizar un respaldo exitoso, independientemente de si es la empresa dueña o una empresa creada:

```json
{
  "RUC": "0991234567001",
  "RUC_Creado": "0991234567002",
  "Sistema": "LSOFT",
  "Fecha_Respaldo": "2025-01-20 20:00:00"
}
```

---

## Reportes y Alertas

### Consultar empresas sin respaldo reciente

```json
{
  "RUC": "0991234567001",
  "Dias_Sin_Acceso": null,
  "Solo_Activas": true
}
```

La respuesta incluye estadísticas:
- `Sin_Respaldo_Nube_7_Dias`: Empresas sin respaldo a la nube en los últimos 7 días
- `Sin_Respaldo_Otra_Unidad_7_Dias`: Empresas sin respaldo a otra unidad en los últimos 7 días

Y para cada empresa:
- `Dias_Sin_Respaldo_Nube`: Días sin respaldo a la nube (null si nunca se ha respaldado)
- `Dias_Sin_Respaldo_Otra_Unidad`: Días sin respaldo a otra unidad (null si nunca se ha respaldado)

Filtrar en la respuesta las empresas donde `Dias_Sin_Respaldo_Nube >= 7` (o el umbral deseado) para el control proactivo de respaldos a la nube.

### Consultar empresas sin acceso reciente

```json
{
  "RUC": "0991234567001",
  "Dias_Sin_Acceso": 30,
  "Solo_Activas": true
}
```

---

## Seguridad

Todos los endpoints utilizan la misma validación de firma HMAC que los demás endpoints del sistema (a través de `Validar_Firma.php`).

---

## Instalación

1. **Ejecutar el script SQL**:
   ```bash
   mysql -u usuario -p base_datos < create_empresas_creadas_table.sql
   ```

2. **Verificar que los archivos PHP estén en el directorio correcto**:
   - `Registrar_Empresa_Creada.php`
   - `Consultar_Empresas_Creadas.php`
   - `Actualizar_Respaldo_Empresa_Creada.php`
   - `Validar_Licencia.php` (modificado)

3. **Modificar el ERP de escritorio** para que llame a estos endpoints cuando sea necesario.

---

## Notas Importantes

1. **ERP Web (LSOFTW)**: El endpoint `Registrar_Sesion` siempre recibe el RUC de la empresa dueña para el control de sesiones. Si se proporciona el parámetro opcional `RUC_Empresa_Creada`, se registra esa empresa en `Empresas_Creadas`. Si no se proporciona, se registra la empresa dueña. Esto permite rastrear accesos y respaldos tanto de la empresa dueña como de empresas creadas cuando usan la versión web.

2. **Validar_Licencia**: Este endpoint NO se modificó. Se mantiene exactamente como estaba, ya que se llama antes del login y en ese momento no se conoce qué empresa/base de datos se usará.

3. **Registro de empresas/bases de datos**: 
   - **ERP de escritorio (LSOFT/FSOFT)**: El registro se hace DESPUÉS del login, cuando el usuario selecciona una empresa/base de datos para trabajar. Esto se hace mediante el endpoint `Registrar_Empresa_Creada`. **Importante**: Incluso si selecciona la propia empresa dueña (RUC_Creado = RUC), debe registrarse para rastrear accesos y respaldos.
   - **ERP Web (LSOFTW)**: El registro se hace en `Registrar_Sesion` cuando se procesan licencias LSOFTW. Si el usuario está usando una empresa creada, se debe proporcionar el parámetro `RUC_Empresa_Creada` (y opcionalmente `Nombre_Empresa_Creada`) en la misma llamada a `Registrar_Sesion`. No requiere llamadas adicionales a otros endpoints.

4. **Fechas de respaldo**: 
   - **Respaldo a nube**: La fecha de respaldo a la nube (`Fecha_Ultimo_Respaldo_Nube`) debe ser actualizada por el proceso de respaldo del ERP cuando se realiza un respaldo al servidor/nube. Este es el campo principal para el control proactivo. El contador `Contador_Respaldos_Nube` se incrementa automáticamente.
   - **Respaldo a otra unidad**: La fecha de respaldo a otra unidad (`Fecha_Ultimo_Respaldo_Otra_Unidad`) se actualiza cuando se realiza un respaldo a otra unidad/carpeta local. Se guarda también la ubicación y el usuario que lo realizó. El contador `Contador_Respaldos_Otra_Unidad` se incrementa automáticamente.
   - Si el proceso de respaldo está apagado o falla, la fecha correspondiente no se actualizará, permitiendo detectar empresas sin respaldo reciente.

5. **Frecuencia de registro**: 
   - **ERP de escritorio (LSOFT/FSOFT)**: Se recomienda llamar a `Registrar_Empresa_Creada` cada vez que el usuario selecciona una empresa/base de datos (ya sea la propia empresa dueña o una creada), para mantener actualizado el último acceso.
   - **ERP Web (LSOFTW)**: El registro se hace en `Registrar_Sesion` cuando se procesan licencias LSOFTW. Si el usuario está usando una empresa creada, se debe proporcionar el parámetro `RUC_Empresa_Creada` en cada llamada a `Registrar_Sesion` para mantener actualizado el último acceso.

6. **Un solo registro por empresa/base de datos**: Una empresa/base de datos tiene un solo registro en `Empresas_Creadas`, independiente del sistema utilizado (LSOFT, FSOFT, LSOFTW). El campo `Sistema` es informativo y se actualiza al sistema más reciente. Esto permite:
   - Contar correctamente cuántas empresas/bases de datos están usando el sistema
   - Tener un solo control de respaldos por empresa/base de datos
   - Saber desde qué sistema se accedió por última vez (campo `Sistema`)

---

## Próximos Pasos

1. Implementar la llamada a estos endpoints desde el ERP de escritorio.
2. Implementar la actualización de fecha de respaldo en el proceso de respaldo automático.
3. Crear reportes y alertas para empresas sin respaldo reciente.
4. Considerar implementar notificaciones automáticas cuando una empresa no ha realizado respaldo en X días.

