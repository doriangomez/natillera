-- Script de base de datos para la Natillera
-- Importa este archivo en phpMyAdmin antes de usar la aplicación
CREATE DATABASE IF NOT EXISTS natillera_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE natillera_db;

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    contraseña_hash VARCHAR(255) NOT NULL,
    rol VARCHAR(20) NOT NULL DEFAULT 'admin'
);

CREATE TABLE socios (
    id_socio INT AUTO_INCREMENT PRIMARY KEY,
    nombre_completo VARCHAR(150) NOT NULL,
    telefono VARCHAR(50),
    numero_polla VARCHAR(50),
    periodicidad_pago VARCHAR(20) DEFAULT 'mensual',
    valor_presupuestado DECIMAL(12,2) DEFAULT 0,
    saldo_socio DECIMAL(12,2) DEFAULT 0,
    activo TINYINT(1) DEFAULT 1
);

CREATE TABLE actividades_maestro (
    id_actividad INT AUTO_INCREMENT PRIMARY KEY,
    nombre_actividad VARCHAR(150) NOT NULL,
    descripcion TEXT,
    afecta_saldo_socio VARCHAR(10) DEFAULT 'neutral',
    afecta_saldo_natillera VARCHAR(10) DEFAULT 'neutral',
    es_prestamo TINYINT(1) DEFAULT 0,
    es_pago_prestamo TINYINT(1) DEFAULT 0,
    es_polla TINYINT(1) DEFAULT 0,
    es_gasto_general TINYINT(1) DEFAULT 0,
    activo TINYINT(1) DEFAULT 1
);

CREATE TABLE medios_pago (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    descripcion VARCHAR(255),
    activo TINYINT(1) DEFAULT 1
);

CREATE TABLE movimientos (
    id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    anio INT DEFAULT NULL,
    mes INT DEFAULT NULL,
    quincena INT DEFAULT 0,
    id_socio INT NULL,
    id_actividad INT NOT NULL,
    motivo VARCHAR(200),
    valor DECIMAL(12,2) NOT NULL,
    medio_consignacion VARCHAR(100),
    id_medio_pago INT NULL,
    es_ingreso TINYINT(1) DEFAULT 0,
    es_egreso TINYINT(1) DEFAULT 0,
    observaciones TEXT,
    modulo VARCHAR(100) DEFAULT NULL,
    usuario_registro VARCHAR(50),
    fecha_registro DATETIME,
    FOREIGN KEY (id_socio) REFERENCES socios(id_socio),
    FOREIGN KEY (id_actividad) REFERENCES actividades_maestro(id_actividad),
    FOREIGN KEY (id_medio_pago) REFERENCES medios_pago(id)
);

CREATE TABLE natillera_estado (
    id_estado INT PRIMARY KEY,
    saldo_actual DECIMAL(12,2) DEFAULT 0
);
INSERT INTO natillera_estado (id_estado, saldo_actual) VALUES (1,0)
    ON DUPLICATE KEY UPDATE saldo_actual = saldo_actual;

CREATE TABLE prestamos (
    id_prestamo INT AUTO_INCREMENT PRIMARY KEY,
    id_socio INT NULL,
    es_particular TINYINT(1) DEFAULT 0,
    id_socio_aval INT NULL,
    nombre_deudor VARCHAR(150),
    fecha_prestamo DATE,
    monto_prestamo DECIMAL(12,2),
    tasa_interes DECIMAL(6,2) DEFAULT 0,
    interes_mensual DECIMAL(12,2) DEFAULT 0,
    saldo_capital_actual DECIMAL(12,2) DEFAULT 0,
    saldo_intereses_actual DECIMAL(12,2) DEFAULT 0,
    estado VARCHAR(20) DEFAULT 'vigente',
    FOREIGN KEY (id_socio) REFERENCES socios(id_socio),
    FOREIGN KEY (id_socio_aval) REFERENCES socios(id_socio)
);

CREATE TABLE cuotas_prestamo (
    id_cuota INT AUTO_INCREMENT PRIMARY KEY,
    id_prestamo INT NOT NULL,
    numero_cuota INT,
    fecha_programada DATE,
    fecha_pago DATE NULL,
    valor_cuota DECIMAL(12,2) DEFAULT 0,
    valor_capital_pagado DECIMAL(12,2) DEFAULT 0,
    valor_interes_pagado DECIMAL(12,2) DEFAULT 0,
    saldo_capital_despues DECIMAL(12,2) DEFAULT 0,
    saldo_intereses_despues DECIMAL(12,2) DEFAULT 0,
    observaciones TEXT,
    FOREIGN KEY (id_prestamo) REFERENCES prestamos(id_prestamo)
);

-- Actividades de ejemplo
INSERT INTO actividades_maestro (nombre_actividad, afecta_saldo_socio, afecta_saldo_natillera, es_prestamo, es_pago_prestamo, es_polla, es_gasto_general) VALUES
('Pago Cuota', 'suma', 'suma', 0, 0, 0, 0),
('Préstamo', 'resta', 'resta', 1, 0, 0, 0),
('Polla', 'suma', 'suma', 0, 0, 1, 0),
('Pago Premio Polla', 'resta', 'resta', 0, 0, 1, 0),
('Pago Abono a Préstamo', 'suma', 'suma', 0, 1, 0, 0),
('Gasto General', 'neutral', 'resta', 0, 0, 0, 1);

CREATE TABLE configuracion_general (
    id_config INT PRIMARY KEY,
    nombre_sistema VARCHAR(200) DEFAULT 'Aplicativo de Natillera creado por Dorian Gómez',
    logo_archivo VARCHAR(255) DEFAULT NULL,
    datos_globales TEXT,
    reglamento_archivo VARCHAR(255) DEFAULT NULL,
    tasa_interes_socio DECIMAL(6,2) DEFAULT 0,
    tasa_interes_particular DECIMAL(6,2) DEFAULT 0
);
INSERT INTO configuracion_general (id_config, nombre_sistema, logo_archivo, datos_globales) VALUES
(1, 'Aplicativo de Natillera creado por Dorian Gómez', NULL, 'Datos generales de la natillera');

-- Periodos configurados para controles y conciliaciones
CREATE TABLE periodos_configuracion (
    id INT AUTO_INCREMENT PRIMARY KEY,
    anio INT NOT NULL,
    mes INT NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    UNIQUE KEY uq_periodo_configuracion (anio, mes)
);

CREATE TABLE polla_resultados (
    id_resultado INT AUTO_INCREMENT PRIMARY KEY,
    anio INT NOT NULL,
    mes INT NOT NULL,
    numero_ganador VARCHAR(50) NOT NULL,
    observaciones TEXT,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_polla_mes (anio, mes)
);

-- Conciliación mensual por medio de pago (una fila por medio y periodo)
CREATE TABLE conciliaciones_medios_pago (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_medio INT NOT NULL,
    anio INT NOT NULL,
    mes INT NOT NULL,
    saldo_sistema DECIMAL(14,2) DEFAULT 0,
    valor_conciliado DECIMAL(14,2) DEFAULT 0,
    diferencia DECIMAL(14,2) DEFAULT 0,
    nota TEXT,
    fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
    cerrado TINYINT(1) DEFAULT 0,
    UNIQUE KEY uq_conciliacion_mensual (id_medio, anio, mes),
    FOREIGN KEY (id_medio) REFERENCES medios_pago(id)
);
