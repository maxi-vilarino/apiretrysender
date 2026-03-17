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
        $this->version = '1.0.0';
        $this->author  = 'Aldaba';

        parent::__construct();

        $this->displayName = $this->l('API Retry Sender');
        $this->description = $this->l('Permite reenviar pedidos a la API desde el detalle del pedido.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionGetAdminOrderButtons');
    }

    public function uninstall()
    {
        return parent::uninstall();
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
