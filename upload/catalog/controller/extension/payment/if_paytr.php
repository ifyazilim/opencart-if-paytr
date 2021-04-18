<?php

class PaytrCatelogManager
{
    /** @var array */
    private $orderInfo;

    /** @var array */
    private $orderProducts;

    /**
     * @param Controller $controller
     * @param int $orderId
     */
    public function __construct($controller, $orderId)
    {
        $this->orderInfo = $controller->model_checkout_order->getOrder($orderId);
        $this->orderProducts = $controller->model_checkout_order->getOrderProducts($orderId);
    }

    /**
     * @return int
     */
    public function getOrderId()
    {
        return (int)$this->orderInfo['order_id'];
    }

    /**
     * @return array
     */
    public function getBasketItems()
    {
        $items = [];

        foreach ($this->orderProducts as $orderProduct) {

            $items[] = [
                'name'  => $orderProduct['name'],
                'price' => $orderProduct['price'],
                'count' => $orderProduct['quantity']
            ];

        }

        return $items;
    }

    public function getIdentityNumber()
    {
        $customFields = isset($this->orderInfo['custom_field']) ? array_values((array)$this->orderInfo['custom_field']) : [];

        $tryIdentityNumber = isset($customFields[0]) ? trim($customFields[0]) : '';

        return strlen($tryIdentityNumber) === 11 ? $tryIdentityNumber : '11111111111';
    }

    public function getPaymentZipCode()
    {
        return ($this->orderInfo['payment_iso_code_2'] !== 'US') ? $this->orderInfo['payment_zone'] : $this->orderInfo['payment_zone_code'];
    }

    public function getShippingZipCode()
    {
        return ($this->orderInfo['shipping_iso_code_2'] !== 'US') ? $this->orderInfo['shipping_zone'] : $this->orderInfo['shipping_zone_code'];
    }

    public function getPaymentFirstName()
    {
        return $this->orderInfo['payment_firstname'];
    }

    public function getPaymentLastName()
    {
        return $this->orderInfo['payment_lastname'];
    }

    public function getPaymentAddress()
    {
        $items = [];

        if ( ! empty($this->orderInfo['payment_city'])) {
            $items[] = $this->orderInfo['payment_city'];
        }

        if ( ! empty($this->orderInfo['payment_address_1'])) {
            $items[] = $this->orderInfo['payment_address_1'];
        }

        if ( ! empty($this->orderInfo['payment_address_2'])) {
            $items[] = $this->orderInfo['payment_address_2'];
        }

        return implode(' ', $items);
    }

    public function getShippingAddress()
    {
        $items = [];

        if ( ! empty($this->orderInfo['shipping_city'])) {
            $items[] = $this->orderInfo['shipping_city'];
        }

        if ( ! empty($this->orderInfo['shipping_address_1'])) {
            $items[] = $this->orderInfo['shipping_address_1'];
        }

        if ( ! empty($this->orderInfo['shipping_address_2'])) {
            $items[] = $this->orderInfo['shipping_address_2'];
        }

        return implode(' ', $items);
    }

    public function getClientIp()
    {
        return empty($this->orderInfo['forwarded_ip']) ? $this->orderInfo['ip'] : $this->orderInfo['forwarded_ip'];
    }

    public function getEmail()
    {
        return $this->orderInfo['email'];
    }

    public function getPhoneNumber()
    {
        return $this->orderInfo['telephone'];
    }

    public function getPaymentTotal()
    {
        return $this->orderInfo['total'];
    }

    public function getCurrencyCode()
    {
        return $this->orderInfo['currency_code'];
    }

    public function getLangCode()
    {
        return in_array($this->orderInfo['language_code'], ['tr', 'tr-tr', 'TR-tr']) ? 'tr' : 'en';
    }
}

class ControllerExtensionPaymentIfPaytr extends Controller
{
    public function index()
    {
        $this->load->language('extension/payment/if_paytr');

        $paymentMethod = $this->config->get('payment_if_paytr_payment_method');

        switch ($paymentMethod) {

            case 'IFRAME':

                // settings not required

                break;

            default:

                return '<div class="text-center">Error: payment method not defined: ' . $paymentMethod . '</div>';

        }

        $data['payment_method'] = $paymentMethod;

        return $this->load->view('extension/payment/if_paytr', $data);
    }

    public function send()
    {
        $this->load->model('checkout/order');

        $manager = new PaytrCatelogManager($this, $this->session->data['order_id']);

        $paymentMethod = $this->config->get('payment_if_paytr_payment_method');

        $moduleData = [

            'merchant_key'          => urlencode($this->config->get('payment_if_paytr_merchant_key')),
            'merchant_salt'         => urlencode($this->config->get('payment_if_paytr_merchant_salt')),
            'client_ip'             => $manager->getClientIp(),
            'email'                 => urlencode($manager->getEmail()),
            'payment_amount'        => $manager->getPaymentTotal(),
            'currency_code'         => $manager->getCurrencyCode(),
            'customer_full_name'    => urlencode($manager->getPaymentFirstName()) . ' ' . urlencode($manager->getPaymentLastName()),
            'customer_address'      => urlencode($manager->getPaymentAddress()),
            'customer_phone_number' => urlencode($manager->getPhoneNumber()),
            'lang_code'             => $manager->getLangCode(),
            'notification_url'      => urlencode($this->url->link('extension/payment/if_paytr/notification', [], true)),
            'basket_items'          => $manager->getBasketItems(),

        ];

        switch ($paymentMethod) {

            case 'IFRAME':

                // eklenecek başka bilgi yok

                break;

        }

        $data = [
            'module'      => 'PAYTR',
            'method'      => $paymentMethod,
            'licence_key' => urlencode($this->config->get('payment_if_paytr_licence_key')),
            'ok_url'      => urlencode($this->url->link('extension/payment/if_paytr/callback', ['status' => 'ok'], true)),
            'fail_url'    => urlencode($this->url->link('extension/payment/if_paytr/callback', ['status' => 'fail'], true)),
            'test'        => ( ! ! $this->config->get('payment_if_paytr_test')),
            'extra_info'  => [
                'order_id' => $manager->getOrderId()
            ],
            'data'        => $moduleData
        ];

        $responseObject = $this->curl_request('INIT', $data);

        if ($responseObject === false) {

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode([
                'error' => 'Bilinmeyen bir hata meydana geldi.'
            ]));

        } else {

            if ($responseObject->success) {

                switch ($responseObject->type) {

                    case 'redirect':

                        $this->response->addHeader('Content-Type: application/json');
                        $this->response->setOutput(json_encode([
                            'redirect' => $responseObject->url
                        ]));

                        break;

                    default:

                        $this->response->addHeader('Content-Type: application/json');
                        $this->response->setOutput(json_encode([
                            'error' => 'Bilinmeyen bir hata meydana geldi.'
                        ]));

                        break;

                }

            } else {

                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode([
                    'error' => $responseObject->message
                ]));

            }

        }
    }

    public function callback()
    {
        $this->load->model('checkout/order');

        $status = isset($this->request->get['status']) ? $this->request->get['status'] : null;

        $this->log->write('if_paytr callback started: ' . $status);

        switch ($status) {

            case 'ok':

                $this->log->write('if_paytr callback ok started: ' . var_export($this->request->post, true));

                $this->response->redirect($this->url->link('checkout/success', '', true));

                break;

            case 'fail':

                $this->log->write('if_paytr callback fail started: ' . var_export($this->request->post, true));

                $this->session->data['error'] = isset($this->request->post['message']) ? $this->request->post['message'] : 'Bilinmeyen bir hata meydana geldi.';

                $this->response->redirect($this->url->link('checkout/checkout', '', true));

                break;

            default:

                $this->session->data['error'] = 'Bilinmeyen bir hata meydana geldi.';

                $this->response->redirect($this->url->link('checkout/checkout', '', true));

                break;

        }

    }

    public function notification()
    {
        $this->load->model('checkout/order');

        $paymentMethod = $this->config->get('payment_if_paytr_payment_method');

        $this->log->write('if_paytr notification started.');

        $transactionId = $this->request->post['transaction_id'];
        $transactionHash = $this->request->post['transaction_hash'];

        $validate_response = $this->validate_transaction($paymentMethod, $transactionId, $transactionHash, [

            'merchant_key'  => urlencode($this->config->get('payment_if_paytr_merchant_key')),
            'merchant_salt' => urlencode($this->config->get('payment_if_paytr_merchant_salt')),

        ]);

        if ($validate_response === false) {

            $this->log->write('if_paytr notification validate hatalı gerçekleşti.');

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode([
                'success' => false,
                'message' => 'Validasyon geçilemedi'
            ]));

        } else {

            if ($validate_response->success) {

                $this->log->write('if_paytr notification validate başarılı oldu: ' . var_export($validate_response, true));

                $this->model_checkout_order->addOrderHistory($validate_response->extra_info->order_id, $this->config->get('payment_if_paytr_order_status_id'));

                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode([
                    'success' => true
                ]));

            } else {

                $this->log->write('if_paytr notification validate başarısız oldu: ' . var_export($validate_response, true));

                $this->response->addHeader('Content-Type: application/json');
                $this->response->setOutput(json_encode([
                    'success' => false,
                    'message' => 'Validasyon başarısız oldu: ' . $validate_response->message
                ]));

            }

        }
    }

    private function validate_transaction($paymentMethod, $id, $hash, $data)
    {
        return $this->curl_request('VALIDATE', [
            'module'           => 'PAYTR',
            'method'           => $paymentMethod,
            'licence_key'      => urlencode($this->config->get('payment_if_paytr_licence_key')),
            'test'             => ( ! ! $this->config->get('payment_if_paytr_test')),
            'transaction_id'   => $id,
            'transaction_hash' => $hash,
            'data'             => $data
        ]);
    }

    private function curl_request($action, $data)
    {
        $curl = curl_init('https://backend.ifyazilim.com/payment/process');

        // curl_setopt($curl, CURLOPT_PORT, 443);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($curl, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', [
            'action=' . $action,
            'data=' . json_encode($data)
        ]));

        $response = curl_exec($curl);

        curl_close($curl);

        if ( ! $response) {

            $this->log->write('IfPaytrPayment failed: ' . curl_error($curl) . '(' . curl_errno($curl) . ')');

            return false;

        }

        return json_decode($response);
    }
}