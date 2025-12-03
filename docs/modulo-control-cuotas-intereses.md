# Definición funcional: Módulo de control de cuotas e intereses

## 1. Objetivo del módulo

El módulo de cuotas e intereses debe permitir controlar cada préstamo otorgado a socios o terceros avalados, diferenciando los movimientos de capital y de intereses, y manteniendo en tiempo real los saldos de capital, intereses acumulados y estado de cumplimiento. También debe generar alertas y reportes de pagos vencidos, intereses pendientes y proyecciones futuras, integrando todos los movimientos al consolidado general y a las conciliaciones sin duplicidades.

## 2. Estructura lógica del control de préstamos

Cada préstamo almacena la siguiente información base:

- **ID del préstamo**
- **Fecha de desembolso**
- **Socio titular** o **tercero con socio avalador obligatorio**
- **Monto inicial del capital**
- **Tasa de interés mensual (%)**
- **Número de cuotas pactadas**
- **Periodicidad (mensual)**
- **Fecha inicial de cobro de intereses**
- **Estado del préstamo:** Activo, Finalizado o En mora
- **Saldo actual de capital**
- **Intereses pendientes acumulados**
- **Intereses pagados históricos**

## 3. Mecánica de cálculo

### 3.1 Interés mensual

- Cada mes se causa: **Interés del mes = Saldo de capital × (tasa mensual / 100)**.
- El interés causado **no suma** al saldo de capital; se acumula en la cuenta de intereses pendientes hasta ser pagado.
- Cada mes se genera un registro automático de interés causado.

### 3.2 Abonos a capital

- Un abono descuenta su valor del saldo de capital.
- Si el capital llega a cero, el préstamo cambia a **Finalizado** y deja de generar intereses.
- Tras cada abono se recalculan automáticamente las cuotas faltantes y la proyección de intereses futuros sobre el nuevo saldo.

### 3.3 Pago de intereses

- Los pagos de intereses se aplican al acumulado pendiente sin modificar el capital.
- Se actualizan los totales de intereses pagados y pendientes, y el estado mensual del período (pagado/pendiente).

### 3.4 Control de cuotas

- Cada mes, si existe interés causado sin pago registrado, el período queda **pendiente** y el préstamo se clasifica **en mora**.
- Al registrar el pago del período, el préstamo vuelve a estado **cumplido** y se elimina la mora.

## 4. Control por período mensual

El sistema gestiona cada préstamo mediante una **matriz mensual de cumplimiento** que cubre desde diciembre de 2025 hasta noviembre de 2026. Por cada período se almacena:

| Mes/Año | Capital inicio | Interés causado | Interés pagado | Abono capital | Capital final | Estado |
| --- | --- | --- | --- | --- | --- | --- |
| Dic 2025 | $1.000.000 | $50.000 | $50.000 | $100.000 | $900.000 | OK |
| Ene 2026 | $900.000 | $45.000 | 0 | 0 | $900.000 | Mora |

## 5. Registros de movimientos

Cada pago o abono genera un movimiento financiero asociado al préstamo y al socio/tercero, con período mensual y validado por el concepto correcto:

| Concepto | Capital | Intereses |
| --- | --- | --- |
| **Abono capital** | Egreso | No afecta |
| **Pago intereses** | Ingreso | No afecta capital |

Los movimientos deben registrar de forma diferenciada la afectación a capital o intereses.

## 6. Validaciones obligatorias

- ❌ Impedir pagar intereses si no hay intereses causados.
- ❌ Impedir abonar capital por encima del saldo pendiente.
- ❌ Impedir registrar préstamos a terceros sin socio avalador.
- ❌ Impedir registrar pagos sin asignación de período.
- ❌ Impedir registrar intereses fuera del mes correspondiente.
- ✅ Recalcular la proyección de cuotas tras cada abono.
- ✅ Mostrar el semáforo de riesgo por nivel de mora:
  - 🟢 Verde: sin mora, pagos al día.
  - 🟡 Amarillo: 1 período pendiente.
  - 🔴 Rojo: 2 o más meses sin pagar.

## 7. Reportes del módulo

### Por socio / avalista
- Total capital prestado.
- Capital pendiente.
- Intereses pagados.
- Intereses vencidos.
- Estado del préstamo.
- Calendario mensual de cumplimiento.

### Globales
- Total de cartera.
- Total capital activo.
- Total intereses cobrados.
- Total intereses pendientes.
- Proyección mensual de ingresos por intereses.

## 8. Exportación

El módulo debe exportar la información en:

- **PDF**: estado de cuenta individual con datos del socio, detalle de cada préstamo, tabla mensual de cuotas, resumen de saldos e intereses y logo institucional.
- **Excel**: listado de todos los préstamos, cuotas por período, morosos y proyección financiera.

## 9. Impacto en el resto del sistema

Todas las acciones del módulo deben reflejarse automáticamente en:

- Dashboard general.
- Consolidado de movimientos.
- Conciliación bancaria.
- Balances por actividad.

Además deben actualizar los saldos de socio/tercero y de la natillera, garantizando coherencia matemática sin inconsistencias entre préstamos, capital o intereses.

## 10. Objetivo final del rediseño

El módulo debe ser 100 % automático, sin cálculos manuales, minimizando errores humanos, mostrando la capacidad real de pago y permitiendo auditoría completa de todos los movimientos.
