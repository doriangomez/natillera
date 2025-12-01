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
    es_gasto_general TINYINT(1) DEFAULT 0
);

CREATE TABLE movimientos (
    id_movimiento INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    id_socio INT NULL,
    id_actividad INT NOT NULL,
    motivo VARCHAR(200),
    valor DECIMAL(12,2) NOT NULL,
    medio_consignacion VARCHAR(100),
    es_ingreso TINYINT(1) DEFAULT 0,
    es_egreso TINYINT(1) DEFAULT 0,
    observaciones TEXT,
    usuario_registro VARCHAR(50),
    fecha_registro DATETIME,
    FOREIGN KEY (id_socio) REFERENCES socios(id_socio),
    FOREIGN KEY (id_actividad) REFERENCES actividades_maestro(id_actividad)
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
    nombre_deudor VARCHAR(150),
    fecha_prestamo DATE,
    monto_prestamo DECIMAL(12,2),
    tasa_interes DECIMAL(6,2) DEFAULT 0,
    numero_cuotas INT DEFAULT 1,
    saldo_capital_actual DECIMAL(12,2) DEFAULT 0,
    saldo_intereses_actual DECIMAL(12,2) DEFAULT 0,
    estado VARCHAR(20) DEFAULT 'vigente',
    FOREIGN KEY (id_socio) REFERENCES socios(id_socio)
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
