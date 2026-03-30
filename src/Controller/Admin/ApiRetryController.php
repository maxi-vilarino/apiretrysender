<?php

namespace Modules\Apiretrysender\Controller\Admin;

use Order;
use Address;
use Customer;
use Cart;
use Exception;
use PrestaShop\PrestaShop\Core\Domain\Order\Exception\OrderException;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ApiRetryController extends FrameworkBundleAdminController
{
    public function sendAction(int $orderId): RedirectResponse
    {
        try {
            $order    = new Order($orderId);
            $customer = new Customer($order->id_customer);
            $address  = new Address($order->id_address_delivery);

            // Leer datos custom de ps_aldaba_orders_details
            $details = \Db::getInstance()->getRow(
                'SELECT * FROM `ps_aldaba_orders_details` WHERE `id_order` = ' . (int) $orderId
            );

            if (!$details) {
                throw new Exception('No se encontraron datos extra del pedido en ps_aldaba_orders_details.');
            }

            // Construir product_list desde ps_order_detail
            $products = \Db::getInstance()->executeS(
                'SELECT od.product_quantity AS cart_quantity, od.product_id AS id_product
                 FROM `ps_order_detail` od
                 WHERE od.id_order = ' . (int) $orderId
            );

            // Construir array compatible con buildApiData del módulo customcheckout
            $orderData = [
                'entrega_ayudante' => (bool) $details['entrega_ayudante'],
                'isTerceros'       => (bool) $details['is_terceros'],
                'restos'           => (bool) $details['restos'],
                'mail_albaran'     => (bool) $details['mail_albaran'],
                'observaciones'    => $details['observaciones'] ?? '',
                'payment_method'   => $details['payment_method'],
                'customer'         => [
                    'nombre'    => $customer->company ?: $customer->firstname . ' ' . $customer->lastname,
                    'email'     => $customer->email,
                    'att'       => $customer->firstname . ' ' . $customer->lastname,
                    'telefonos' => $address->phone ?: $address->phone_mobile,
                    'cp'        => $address->postcode,
                    'direccion' => $address->address1,
                    'poblacion' => $address->city,
                ],
            ];

            $cartData = ['product_list' => $products];

            // Usar customcheckout para buildApiData y callApi
            $customCheckout = \Module::getInstanceByName('customcheckout');
            $apiData        = $customCheckout->buildApiData($orderData, $cartData);
            //$result         = $customCheckout->callApi('pedidos', 'POST', $apiData);
            $result =
                [
                    'success' => true,
                    'data'    => [
                        'referenciaPedido' => 'REF123456789', // Ejemplo de referencia generada por la API
                    ],
                ];

            if ($result) {
                // Guardar referencia generada y cambiar estado del pedido
                \Db::getInstance()->update('aldaba_orders_details', [
                    'api_reference' => pSQL($result['data']['referenciaPedido']),
                ], 'id_order = ' . (int) $orderId);

                $history                = new \OrderHistory();
                $history->id_order      = $orderId;
                $history->changeIdOrderState(
                    (int) \Configuration::get('PS_OS_PAYMENT'), // estado "Pago aceptado" o el que corresponda
                    $order
                );
                $history->add();

                $this->addFlash('success', $this->trans('Pedido enviado a la API correctamente.', [], 'Modules.Apiretrysender.Admin'));
            } else {
                throw new Exception('La API no devolvió una respuesta válida.');
            }
        } catch (Exception $e) {
            \PrestaShopLogger::addLog('ApiRetrySender Error: ' . $e->getMessage(), 3);
            $this->addFlash('error', $this->trans('Error al enviar el pedido a la API: ', [], 'Modules.Apiretrysender.Admin') . $e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_view', ['orderId' => $orderId]);
    }
}
