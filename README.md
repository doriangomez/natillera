# Aplicativo de Natillera

Panel web para administrar una natillera en **PHP + MySQL**. El sistema centraliza la gestión de socios, aportes, préstamos, rifas, pollas, movimientos, conciliaciones, liquidaciones, reportes y configuración general, con autenticación por roles y una interfaz responsive basada en Bootstrap.

## Tabla de contenido

- [Características principales](#características-principales)
- [Tecnologías](#tecnologías)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Requisitos](#requisitos)
- [Instalación local](#instalación-local)
- [Credenciales iniciales](#credenciales-iniciales)
- [Configuración](#configuración)
- [Módulos disponibles](#módulos-disponibles)
- [Base de datos](#base-de-datos)
- [Reportes y exportaciones](#reportes-y-exportaciones)
- [Seguridad y roles](#seguridad-y-roles)
- [Mantenimiento](#mantenimiento)
- [Documentación funcional](#documentación-funcional)

## Características principales

- Autenticación de usuarios con rol de administrador.
- Panel principal con indicadores financieros y operativos de la natillera.
- Administración de socios activos, grupos, saldos y números de polla.
- Registro de movimientos contables con afectación automática a saldos de socios y de la natillera.
- Gestión de préstamos a socios o particulares, avales, cuotas, intereses, estados y periodos.
- Matrices de seguimiento para préstamos y cuotas.
- Configuración de actividades maestras, medios de pago, periodos y parámetros generales.
- Módulos para pollas, rifas, gastos, retiros de caja, conciliaciones y liquidaciones.
- Reportes en PDF, CSV y archivos tipo Excel según el módulo.
- Manejo de reglamento institucional y copias de respaldo.
- Registro de logs de base de datos y errores de conexión.

## Tecnologías

- **Backend:** PHP 8+ con PDO.
- **Base de datos:** MySQL/MariaDB.
- **Frontend:** HTML, CSS, JavaScript, Bootstrap 5 y Bootstrap Icons.
- **Reportes PDF:** Dompdf.
- **Gestión de dependencias:** Composer.

## Estructura del proyecto

```text
.
├── actions/                 # Controladores para guardar, exportar, autenticar y procesar formularios
├── config/                  # Configuración de conexión a base de datos
├── docs/                    # Documentación funcional del proyecto
├── includes/                # Autenticación, layout, helpers, logger y utilidades compartidas
├── logs/                    # Archivos de log generados por la aplicación
├── public/                  # Vistas públicas autenticadas y punto de entrada web
├── database.sql             # Script de creación y actualización de base de datos
├── composer.json            # Dependencias PHP del proyecto
└── README.md                # Documentación general
```

## Requisitos

Antes de instalar, asegúrate de tener:

- PHP 8.0 o superior.
- MySQL 5.7+/MariaDB 10.4+.
- Composer.
- Servidor web local como Apache, Nginx, Laragon, WAMP o XAMPP.
- Extensiones PHP habituales para PDO MySQL y generación de reportes.

## Instalación local

1. **Clona o copia el proyecto** en tu servidor local:

   ```bash
   git clone <url-del-repositorio> natillera
   cd natillera
   ```

2. **Instala las dependencias PHP**:

   ```bash
   composer install
   ```

3. **Crea la base de datos** importando el archivo `database.sql` desde phpMyAdmin, MySQL Workbench o consola:

   ```bash
   mysql -u root -p < database.sql
   ```

4. **Configura la conexión** en `config/db.php` si tus credenciales son diferentes:

   ```php
   $host = 'localhost';
   $db   = 'natillera_db';
   $user = 'root';
   $pass = '';
   ```

5. **Crea el usuario administrador inicial** ejecutando:

   ```bash
   php actions/create_admin.php
   ```

6. **Publica la carpeta `public/`** como raíz del sitio o accede directamente desde tu servidor local, por ejemplo:

   ```text
   http://localhost/natillera/public/login.php
   ```

## Credenciales iniciales

El script `actions/create_admin.php` crea el siguiente usuario de administración:

```text
Usuario: admin
Contraseña: admin123
Rol: admin
```

> Recomendación: cambia esta contraseña después del primer ingreso desde el módulo de usuarios o actualízala directamente en la base de datos.

## Configuración

El sistema permite administrar parámetros generales desde el módulo de configuración:

- Nombre del sistema.
- Logo institucional.
- Datos globales de la natillera.
- Reglamento descargable.
- Tasas de interés para socios y particulares.
- Actividad usada para pago de cuotas.
- Usuarios administrativos.
- Medios de pago.
- Periodos de control para préstamos, pollas y conciliaciones.

## Módulos disponibles

| Módulo | Descripción |
| --- | --- |
| Inicio/estadísticas | Indicadores generales, saldos, préstamos, ingresos y egresos. |
| Socios | Registro, edición, activación y consulta de socios. |
| Movimientos | Registro de ingresos, egresos, aportes y operaciones contables. |
| Préstamos | Creación de préstamos, pagos, intereses, cuotas, historial y matriz de seguimiento. |
| Liquidaciones | Liquidación definitiva o anticipada de socios y préstamos. |
| Pollas | Gestión de números, aportes, premios y resultados por periodo. |
| Rifas | Administración de rifas, boletas, asignaciones y premios. |
| Gastos y retiros | Control de gastos generales y retiros de caja. |
| Conciliaciones | Revisión de movimientos y saldos para control interno. |
| Reportes | Exportaciones y documentos PDF/CSV. |
| Copias | Generación de respaldos y exportaciones operativas. |
| Configuración | Parámetros globales, actividades, usuarios y medios de pago. |

## Base de datos

El archivo `database.sql` crea y actualiza las tablas necesarias para operar el sistema. Entre las tablas principales se incluyen:

- `usuarios`: credenciales y roles.
- `socios`: información de socios, saldos, grupos y número de polla.
- `actividades_maestro`: reglas contables de cada actividad.
- `medios_pago`: canales de pago y consignación.
- `natillera_estado`: saldo global de la natillera.
- `prestamos`: préstamos a socios o particulares.
- `movimientos`: registro transaccional principal.
- `cuotas_prestamo`: cuotas y pagos de préstamos.
- `periodos_prestamo`: control mensual de capital e intereses.
- `periodos_configuracion`: periodos activos para control operativo.
- Tablas complementarias para rifas, pollas, liquidaciones, conciliaciones, reglamento y respaldos.

## Reportes y exportaciones

El proyecto incluye acciones para generar:

- Estados de préstamos en PDF.
- Movimientos individuales de socios en PDF.
- Reporte de socios por polla en PDF.
- Matriz de préstamos.
- Exportaciones CSV.
- Copias o dumps operativos de la base de datos.

Para los PDFs se usa `dompdf/dompdf`, instalado mediante Composer.

## Seguridad y roles

- Las páginas internas validan sesión activa antes de permitir el acceso.
- La sesión expira después de 30 minutos de inactividad.
- Algunas acciones requieren rol `admin`.
- Las contraseñas se almacenan con hash seguro mediante `password_hash`.
- Se recomienda no publicar credenciales reales en el repositorio y ajustar `config/db.php` según el entorno.

## Mantenimiento

Tareas recomendadas:

- Cambiar la contraseña inicial del administrador.
- Realizar respaldos periódicos de la base de datos.
- Revisar los archivos en `logs/` ante errores de conexión o consultas.
- Mantener actualizado Composer:

  ```bash
  composer update
  ```

- Probar los cambios en un entorno local antes de llevarlos a producción.

## Documentación funcional

- [Definición funcional: Módulo de control de cuotas e intereses](docs/modulo-control-cuotas-intereses.md)
