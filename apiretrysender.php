<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/../../modules/customcheckout/customcheckout.php';

class ApiRetrySender extends Module
{
    public function __construct()
    {
        $this->name    = 'apiretrysender';
        $this->tab     = 'administration';
        $this->version = '1.1.0';
        $this->author  = 'Aldaba';

        parent::__construct();

        $this->displayName = $this->l('API Retry Sender');
        $this->description = $this->l('Permite reenviar pedidos a la API desde el detalle del pedido.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionGetAdminOrderButtons')
            && $this->createTable();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && $this->dropTable();
    }

    private function createTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'aldaba_orders_details` (
            `id_aldaba_order`      INT(11) NOT NULL AUTO_INCREMENT,
            `id_order`             INT(10) UNSIGNED NOT NULL,
            `entrega_ayudante`     TINYINT(1) NOT NULL DEFAULT 0,
            `is_terceros`          TINYINT(1) NOT NULL DEFAULT 0,
            `restos`               TINYINT(1) NOT NULL DEFAULT 0,
            `mail_albaran`         VARCHAR(1) NOT NULL DEFAULT \'N\',
            `observaciones`        TEXT,
            `payment_method`       VARCHAR(100) NOT NULL DEFAULT \'\',
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
                REFERENCES `' . _DB_PREFIX_ . 'orders` (`id_order`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

        return \Db::getInstance()->execute($sql);
    }


    private function dropTable(): bool
    {
        return \Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'aldaba_orders_details`'
        );
    }

    public function hookActionGetAdminOrderButtons(array $params)
    {
        $order = new Order((int) $params['id_order']);

        // Solo mostrar el botón si el estado es "Error en API" (id = 14)
        if ((int) $order->current_state !== 14) {
            return;
        }

        /** @var \Symfony\Component\Routing\RouterInterface $router */
        $router = $this->get('router');
        $url    = $router->generate('apiretrysender_send', [
            'orderId' => (int) $params['id_order'],
        ]);

        /** @var \PrestaShopBundle\Controller\Admin\Sell\Order\ActionsBarButtonsCollection $buttons */
        $params['actions_bar_buttons_collection']->add(
            new \PrestaShopBundle\Controller\Admin\Sell\Order\ActionsBarButton(
                'btn-primary',
                ['href' => $url],
                $this->l('Enviar a API')
            )
        );
    }
}
