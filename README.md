# ApiRetrySender - Módulo PrestaShop

Módulo para PrestaShop 8.x que permite reenviar manualmente pedidos a la API externa desde el detalle del pedido en el back office.

## Descripción

Cuando un pedido queda en estado **"Error en API"** (id_state: 14), aparece un botón **"Enviar a API"** en la barra de acciones del pedido. Al pulsarlo, el módulo reconstruye los datos del pedido y realiza el envío a la API de DSG.

## Requisitos

- PrestaShop 8.1.x
- Módulo `customcheckout` instalado y activo
- Tabla `ps_aldaba_orders_details` creada en la base de datos
- Métodos `buildApiData()` y `callApi()` públicos en `customcheckout`

## Instalación

1. Copiar la carpeta `apiretrysender` en `/modules/`
2. Ir a **Módulos → Gestor de módulos** en el back office
3. Buscar _API Retry Sender_ e instalar

## Estructura

apiretrysender/
├── apiretrysender.php ← Clase principal, hook del botón
├── config/
│ └── routes.yml ← Ruta Symfony del controlador
├── src/
│ └── Controller/
│ └── Admin/
│ └── ApiRetryController.php ← Lógica de envío a la API
├── .gitignore
└── README.md

## Funcionamiento

1. El hook `actionGetAdminOrderButtons` detecta si el pedido está en estado **Error en API**
2. Si es así, inyecta el botón **"Enviar a API"** en la barra superior del pedido
3. Al pulsar, el controlador `ApiRetryController` recoge los datos de:
   - `ps_orders` → datos base del pedido
   - `ps_customer` y `ps_address` → datos del cliente y dirección
   - `ps_order_detail` → productos del pedido
   - `ps_aldaba_orders_details` → datos extra (ayudante, terceros, restos, etc.)
4. Llama a la API mediante el módulo `customcheckout`
5. Si la respuesta es correcta, actualiza el estado del pedido y guarda la referencia API
6. Muestra un mensaje de éxito o error en el back office

## Tabla requerida

```sql
CREATE TABLE IF NOT EXISTS `ps_aldaba_orders_details` (
    `id_aldaba_order`      INT(11) NOT NULL AUTO_INCREMENT,
    `id_order`             INT(10) UNSIGNED NOT NULL,
    `entrega_ayudante`     TINYINT(1) NOT NULL DEFAULT 0,
    `is_terceros`          TINYINT(1) NOT NULL DEFAULT 0,
    `restos`               TINYINT(1) NOT NULL DEFAULT 0,
    `mail_albaran`         VARCHAR(1) NOT NULL DEFAULT 'N',
    `observaciones`        TEXT,
    `payment_method`       VARCHAR(100) NOT NULL DEFAULT '',
    `recargos`             DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `recargo_equivalencia` DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `total_iva`            DECIMAL(20,6) NOT NULL DEFAULT 0.000000,
    `api_reference`        VARCHAR(50) DEFAULT NULL,
    `date_add`             DATETIME NOT NULL,
    `date_upd`             DATETIME ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_aldaba_order`),
    UNIQUE KEY `id_order` (`id_order`),
    CONSTRAINT `fk_aldaba_order`
        FOREIGN KEY (`id_order`)
        REFERENCES `ps_orders` (`id_order`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Campos de la tabla

| Campo                  | Tipo             | Descripción                                               |
| ---------------------- | ---------------- | --------------------------------------------------------- |
| `id_aldaba_order`      | INT(11)          | ID único del registro (Auto increment)                    |
| `id_order`             | INT(10) UNSIGNED | ID del pedido en PrestaShop (FK a ps_orders)              |
| `entrega_ayudante`     | TINYINT(1)       | Flag indicador de entrega con ayudante (0/1)              |
| `is_terceros`          | TINYINT(1)       | Flag indicador de pedido de terceros (0/1)                |
| `restos`               | TINYINT(1)       | Flag indicador de restos (0/1)                            |
| `mail_albaran`         | VARCHAR(1)       | Envío de albarán por email ('N' por defecto, 'S' para sí) |
| `observaciones`        | TEXT             | Notas adicionales del pedido                              |
| `payment_method`       | VARCHAR(100)     | Método de pago utilizado                                  |
| `recargos`             | DECIMAL(20,6)    | Importe de recargos                                       |
| `recargo_equivalencia` | DECIMAL(20,6)    | Importe de recargo de equivalencia                        |
| `total_iva`            | DECIMAL(20,6)    | Total del IVA del pedido                                  |
| `api_reference`        | VARCHAR(50)      | Referencia retornada por la API                           |
| `date_add`             | DATETIME         | Fecha y hora de creación del registro                     |
| `date_upd`             | DATETIME         | Fecha y hora de última actualización (auto)               |
