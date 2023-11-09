<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Config;
use App\Models\Paylist;
use App\Services\Auth;
use App\Services\Exchange;
use App\Services\View;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use RedisException;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use voku\helper\AntiXSS;

final class NowPayments extends Base
{
    public static function _name(): string
    {
        return 'nowpayments';
    }

    public static function _enable(): bool
    {
        return self::getActiveGateway('nowpayments');
    }

    public static function _readableName(): string
    {
        return 'NowPayments';
    }


    /**
     * @param string $str1
     * @param string $str2
     * @return bool
     */
    public function hashEqual($str1, $str2)
    {
        if (function_exists('hash_equals')) {
            return \hash_equals($str1, $str2);
        }

        if (strlen($str1) != strlen($str2)) {
            return false;
        } else {
            $res = $str1 ^ $str2;
            $ret = 0;

            for ($i = strlen($res) - 1; $i >= 0; $i--) {
                $ret |= ord($res[$i]);
            }
            return !$ret;
        }
    }
    
    private function _curlPost($url,$params=false){

        $key = Config::obtain('nowpayments_key');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt(
            $ch, CURLOPT_HTTPHEADER, array('x-api-key: ' .$key, 'Content-Type: application/json')
        );
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }

    /**
     * @throws GuzzleException
     * @throws RedisException
     */
    public function purchase(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $antiXss = new AntiXSS();

        $price = (float) $antiXss->xss_clean($request->getParam('price'));
        $invoice_id = $antiXss->xss_clean($request->getParam('invoice_id'));
        $trade_no = self::generateGuid();

        if ($price < Config::obtain('nowpayments_min_recharge') ||
            $price > Config::obtain('nowpayments_max_recharge')
        ) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '非法的金额',
            ]);
        }

        $user = Auth::getUser();
        $pl = new Paylist();

        $pl->userid = $user->id;
        $pl->total = $price;
        $pl->invoice_id = $invoice_id;
        $pl->tradeno = $trade_no;
        $pl->save();

        $exchange_amount = Exchange::exchange($price, 'USD', Config::obtain('nowpayments_currency'));

        /* 
        curl --location 'https://api.nowpayments.io/v1/invoice' \
        --header 'x-api-key: abcdefg' \
        --header 'Content-Type: application/json' \
        --data '{
        "price_amount": 1000,
        "price_currency": "usd",
        "order_id": "RGDBP-21314",
        "order_description": "Apple Macbook Pro 2019 x 1",
        "ipn_callback_url": "https://nowpayments.io",
        "success_url": "https://nowpayments.io",
        "cancel_url": "https://nowpayments.io"
        }'

        */

        $params = [
            'price_amount' => $exchange_amount,
            'price_currency' => "usd",
            'order_id' => $trade_no,
            'ipn_callback_url' => $_ENV['baseUrl'] . '/payment/notify/nowpayments',
            'success_url' => $_ENV['baseUrl'] . '/user/payment/return/nowpayments',
            'cancel_url' => $_ENV['baseUrl'] . '/user/invoice',
        ];
        $params_string = json_encode($params);

        $nowpayments_url = Config::obtain('nowpayments_url');

        $ret = array();
        try {
            $ret_raw = self::_curlPost($nowpayments_url . '/invoice', $params_string);

            $ret = json_decode($ret_raw, true);

            if(empty($ret['invoice_url'])) {
                throw Exception("error!");
            }

        } catch (Exception $e) {
            return $response->withJson([
                'ret' => 0,
                'msg' => 'NowPayments API error',
            ]);
        }

        return $response->withRedirect($ret['invoice_url']);
    }

    public function notify($request, $response, $args): ResponseInterface
    {
        $payload = trim(file_get_contents('php://input'));
        $json_param = json_decode($payload, true); 

        if (isset($_SERVER['HTTP_X_NOWPAYMENTS_SIG']) && !empty($_SERVER['HTTP_X_NOWPAYMENTS_SIG'])) {
            $received_hmac = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'];
            if ($json_param !== false && !empty($json_param)) {
                ksort($json_param);
                $sorted_request_json = json_encode($json_param, JSON_UNESCAPED_SLASHES);
                $computedSignature = hash_hmac("sha512", $sorted_request_json, trim($this->config['ipn']));

                if (! self::hashEqual($received_hmac, $computedSignature)) {
                    throw new \ErrorException('HMAC signature does not match');
                    //abort(400, 'HMAC signature does not match');
                }
            } else {
                throw new \ErrorException('HMAC signature does not match');
                //abort(400, 'HMAC signature does not match');
            }
            $out_trade_no = $json_param['order_id'];
            $pay_trade_no=$json_param['payment_id'];
            if ($json_param['payment_status'] == 'confirmed' || $json_param['payment_status'] == 'finished') {
                return [
                    'trade_no' => $out_trade_no,
                    'callback_no' => $pay_trade_no
                ];
            }
            return $response->write('ok');
        }

        return $response->write('error');
    }

    /**
     * @throws Exception
     */
    public static function getPurchaseHTML(): string
    {
        return View::getSmarty()->fetch('gateway/nowpayments.tpl');
    }

    public function getReturnHTML($request, $response, $args): ResponseInterface
    {
        $antiXss = new AntiXSS();

        $money = $antiXss->xss_clean($request->getParam('money'));

        $html = <<<HTML
            你已成功充值 {$money} 元，正在跳转..
            <script>
                setTimeout(function() {
                    location.href="/user/invoice";
                },500)
            </script>
            HTML;

        return $response->write($html);
    }
}
