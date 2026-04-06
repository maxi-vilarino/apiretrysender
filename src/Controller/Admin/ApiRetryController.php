<?php

namespace Modules\Apiretrysender\Controller\Admin;

use Order;
use Address;
use Customer;
use Exception;
use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ApiRetryController extends FrameworkBundleAdminController
{
    public function sendAction(int $orderId): RedirectResponse
    {
        try {
            $order    = new Order($orderId);
            $customer = new Customer($order->id_customer);
            $idCustomer = $customer->id;

            $details = \Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'aldaba_orders_details` WHERE `id_order` = ' . (int) $orderId
            );

            $addressData = \Db::getInstance()->getRow(
                'SELECT * FROM `' . _DB_PREFIX_ . 'address` WHERE id_address = ' . (int) $order->id_address_delivery
            );


            if (!$details) {
                throw new Exception('No se encontraron datos extra del pedido.');
            }

            $products = \Db::getInstance()->executeS(
                'SELECT od.product_quantity AS cart_quantity, od.product_id AS id_product
                 FROM `' . _DB_PREFIX_ . 'order_detail` od
                 WHERE od.id_order = ' . (int) $orderId
            );

            $orderData = [
                'entrega_ayudante' => (bool) $details['entrega_ayudante'],
                'isTerceros'       => (bool) $details['is_terceros'],
                'restos'           => (bool) $details['restos'],
                'mail_albaran'     => $details['mail_albaran'],
                'observaciones'    => $details['observaciones'] ?? '',
                'payment_method'   => $details['payment_method'],
                'customer' => [
                    'nombre'    => $addressData['company'],
                    'email'     => $addressData['observaciones'],
                    'att'       => $addressData['att'] ?? '',
                    'telefonos' => $addressData['phone'] ?: $addressData['phone_mobile'],
                    'cp'        => $addressData['postcode'],
                    'direccion' => $addressData['address1'],
                    'poblacion' => $addressData['city'],
                ],
            ];

            $cartData = ['product_list' => $products];

            $apiData = $this->buildApiData($orderData, $cartData);
            \PrestaShopLogger::addLog('ApiRetrySender API Data: ' . json_encode($apiData, true), 1);
            $result  = $this->callApi('pedidos', 'POST', $apiData, $idCustomer);

            if ($result) {
                \Db::getInstance()->update('aldaba_orders_details', [
                    'api_reference' => pSQL($result[0]['pedido'] ?? $result[0]['referencia'] ?? ''),
                ], 'id_order = ' . (int) $orderId);

                $history           = new \OrderHistory();
                $history->id_order = $orderId;
                $history->changeIdOrderState(
                    (int) \Configuration::get('PS_OS_PAYMENT'),
                    $order
                );
                $history->add();

                $this->addFlash('success', 'Pedido enviado a la API correctamente.');
            } else {
                throw new Exception('La API no devolvió una respuesta válida.');
            }
        } catch (Exception $e) {
            \PrestaShopLogger::addLog('ApiRetrySender Error: ' . $e->getMessage(), 3);
            $this->addFlash('error', 'Error al enviar el pedido a la API: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_orders_view', ['orderId' => $orderId]);
    }

    private function buildApiData($order, $cart)
    {
        $reference    = 'PED' . substr(md5(uniqid()), 0, 9);
        $lineas_order = [];

        foreach ($cart['product_list'] as $product) {
            $dataDb         = $this->getCodDsgProduct($product['id_product']);
            $lineas_order[] = [
                'cantidad'   => $product['cart_quantity'],
                'notas'      => '',
                'referencia' => strval($dataDb['cod_dsg']),
            ];
        }

        return [
            'ayudante'             => $order['entrega_ayudante'],
            'cliente'              => ['nombreJuridico' => $order['customer']['nombre'] ?? ''],
            'contacto'             => [
                'email'    => $order['customer']['email']     ?? '',
                'nombre'   => $order['customer']['att']       ?? '',
                'telefono' => $order['customer']['telefonos'] ?? '',
            ],
            'direccionEnvio'       => [
                'codigoPostal' => $order['customer']['cp']        ?? '',
                'direccion'    => $order['customer']['direccion'] ?? '',
                'poblacion'    => $order['customer']['poblacion'] ?? '',
            ],
            'envioATercero'        => $order['isTerceros'],
            'fechaEntrega'         => date('Y-m-d'),
            'lineas'               => $lineas_order,
            'notas'                => $order['observaciones']  ?? '',
            'observacion_albaran'  => '',
            'observacion_etiqueta' => '',
            'referenciaPedido'     => $reference,
            'restos'               => $order['restos'],
            'valorado'             => $order['mail_albaran'],
            'forma_pago'           => $order['payment_method'],
        ];
    }

    private function callApi($endpoint, $method, $data = null, $idCustomer = null)
    {
        $DSG_API_URL = 'http://159.69.206.190:8208/';
        try {
            $client = new \GuzzleHttp\Client();

            $spyroInfo = $this->getDsgSpyroCuentas($idCustomer);
            $spyroId = $spyroInfo['spyro_id'] ?? 'No encontrado';
            $spyroPw = $spyroInfo['spyro_pw'] ?? 'No encontrado';

            $token = $this->getUserToken($spyroId, $spyroPw);
            if (empty($token)) {
                throw new Exception("Token de autenticación no encontrado");
            }
            $response   = $client->request($method, $DSG_API_URL . $endpoint, [
                "headers"     => ["Authorization" => "Bearer " . $token, "Content-Type" => "application/json"],
                "json"        => $data,
                "http_errors" => false,
            ]);
            $statusCode = $response->getStatusCode();
            $body       = $response->getBody()->getContents();

            if ($statusCode == 200) {
                $decoded = json_decode($body, true);
                \PrestaShopLogger::addLog('Response API data: ' . json_encode($decoded), 1);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
                throw new Exception("Error JSON: " . json_last_error_msg());
            }
            throw new Exception("Status: $statusCode | Body: $body");
        } catch (Exception $e) {
            \PrestaShopLogger::addLog('ApiRetrySender callApi Error: ' . $e->getMessage(), 3);
            return false;
        }
    }

    private function getUserToken($id, $password)
    {
        $DSG_API_URL = 'http://159.69.206.190:8208';
        $client = new \GuzzleHttp\Client();
        try {
            $response = $client->request('POST', $DSG_API_URL . '/token', [
                'form_params' => [
                    'username' => $id,
                    'password' => $password,
                ],
            ]);

            $data = json_decode($response->getBody(), true);
            \PrestaShopLogger::addLog('Token obtenido: ' . ($data['access_token'] ? 'Sí' : 'No'), 1);
            return $data['access_token'] ?? null;
        } catch (\Exception $e) {
            \PrestaShopLogger::addLog('Error al obtener token: ' . $e->getMessage(), 3);
            return null;
        }
    }

    private function getCodDsgProduct($product_id)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'aldaba_productform_custom_product WHERE id = "' . pSQL($product_id) . '"';
        $row = \Db::getInstance()->getRow($sql);
        return $row ?: null;
    }

    private function getDsgSpyroCuentas($idCustomer)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'spyro_cuentas 
                WHERE id_customer = ' . $idCustomer;
        $result = \Db::getInstance()->getRow($sql);
        return $result;
    }
}
