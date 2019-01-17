<?php

/**
 *
 * @author ivanov.uz
 * @name ivanov.uz
 * @description Плагин оплаты через paycom.uz.
 *
 * Поля, доступные в виде параметров настроек плагина, указаны в файле lib/config/settings.php.

 */

require_once waSystemConfig::getActive()->getRootPath() . '/wa-plugins/payment/paycom/lib/vendor/paycom/vendor/autoload.php';

use Paycom\Request as Request;
use Paycom\Response as PaycomResponse;
use Paycom\PaycomException as PaycomException;
use Paycom\Format as Format;
use Paycom\Transaction as Transaction;
use Paycom\Merchant as Merchant;

/**
 * Class paycomPayment
 */
class paycomPayment extends waPayment implements waIPayment
{
    /**
     * @var
     */
    public $config;

    /**
     * @var $request Request
     */
    public $request;

    /**
     * @var $response PaycomResponse
     */
    public $response;

    /**
     * @var $merchant Merchant
     */
    public $merchant;

    /**
     * @var null
     */
    private $redirect = null;

    /**
     * @var array
     */
    private $transaction = array();
    /**
     * @var array
     */
    private $transaction_data = array();

    /**
     * @return array
     */
    private function getConfig() {
        return [
            // Get it in merchant's cabinet in cashbox settings
            'merchant_id'   => $this->KASSA_ID,
            // Login is always "Paycom"
            'login'         => 'Paycom',
            'keyFile'       => null,
            'key'           => $this->secret_key,
        ];
    }

    /**
     * Возвращает ISO3-коды валют, поддерживаемых платежной системой,
     * допустимые для выбранного в настройках протокола подключения и указанного номера кошелька продавца.
     *
     * @see waPayment::allowedCurrency()
     * @return mixed
     */
    public function allowedCurrency()
    {
        return array('UZS');
    }


    /**
     * Генерирует HTML-код формы оплаты.
     *
     * Платежная форма может отображаться во время оформления заказа или на странице просмотра ранее оформленного заказа.
     * Значение атрибута "action" формы может содержать URL сервера платежной системы либо URL текущей страницы (т. е. быть пустым).
     * Во втором случае отправленные пользователем платежные данные снова передаются в этот же метод для дальнейшей обработки, если это необходимо,
     * например, для проверки, сохранения в базу данных, перенаправления на сайт платежной системы и т. д.
     * @param array $payment_form_data Содержимое POST-запроса, полученное при отправке платежной формы
     *     (если в формы оплаты не указано значение атрибута "action")
     * @param waOrder $order_data Объект, содержащий всю доступную информацию о заказе
     * @param bool $auto_submit Флаг, обозначающий, должна ли платежная форма автоматически отправить данные без участия пользователя
     *     (удобно при оформлении заказа)
     * @return string HTML-код платежной формы
     * @throws waException
     */

    public function payment($payment_form_data, $order_data, $auto_submit = false)
    {
        // заполняем обязательный элемент данных с описанием заказа
        if (empty($order_data['description'])) {
            $order_data['description'] = 'Заказ '.$order_data['order_id'];
        }

        // вызываем класс-обертку, чтобы гарантировать использование данных в правильном формате
        $order = waOrder::factory($order_data);
		$kassaID = $this->KASSA_ID; //Нужно заменить параметр на полученный ID

		$order_row = array(
            $this->app_id,
            $this->merchant_id,
            $order['id'],
        );
		$transAmount = (number_format($order->total, 0, '.', '') * 100);
		
        $hidden_fields = array(
            'merchant'               => $kassaID,
			'amount'                 => $transAmount,
			'account[orderid]'    	 => implode('#', $order_row),
            'lang'  				 => 'ru',
        );

        $view = wa()->getView();

        $view->assign('url', wa()->getRootUrl());
        $view->assign('hidden_fields', $hidden_fields);

        $view->assign('form_url', $this->getEndpointUrl());
        $view->assign('form_options', $this->getFormOptions());
        $view->assign('auto_submit', $auto_submit);

        // для отображения платежной формы используем собственный шаблон
        return $view->fetch($this->path.'/templates/payment.html');
    }

    /**
     * @throws PaycomException
     * @throws waException
     */
    private function initRequest() {
        $this->request  = new Request();

        if ($this->merchant_id && $this->order_id && $this->app_id) {
            return $this;
        }

        $this->app_id = null;
        $this->merchant_id = null;
        $this->order_id = null;
        $this->transaction = array();

        if (isset($this->request->params['account']) && isset($this->request->params['account']['orderid'])) {
            $order_array = explode('#', $this->request->params['account']['orderid']);

            if (is_array($order_array) && count($order_array) == 3) {
                $this->app_id = $order_array[0];
                $this->merchant_id = $order_array[1];
                $this->order_id = $order_array[2];
            }
        }
        elseif (isset($this->request->params['id'])) {
            $transaction_model = new waTransactionModel();
            $this->transaction = $transaction_model->getByField('native_id', $this->request->params['id']);

            if (!empty($this->transaction)) {
                $this->app_id = $this->transaction['app_id'];
                $this->merchant_id = $this->transaction['merchant_id'];
                $this->order_id = $this->transaction['native_id'];
            }
        }
        $this->init();
        return $this;
    }

    /**
     * Инициализация плагина для обработки вызовов от платежной системы.
     *
     * Для обработки вызовов по URL вида /payments.php/uzclick/* необходимо определить
     * соответствующее приложение и идентификатор, чтобы правильно инициализировать настройки плагина.
     * @param array $request Данные запроса (массив $_REQUEST)
     * @return waPayment
     * @throws PaycomException
     * @throws waPaymentException
     * @throws waException
     */
    protected function callbackInit($request)
    {
        $request = (array)json_decode(file_get_contents('php://input'), true);

        waLog::dump($request, 'test-req.log');

        $this->initRequest();
        $this->config   = $this->getConfig();
        $this->response = new PaycomResponse($this->request);
        $this->merchant = new Merchant($this->config);

        return parent::callbackInit($request);
	}

    /**
     * @param object $transaction_raw_data
     * @return array
     */
    protected function formalizeData($transaction_raw_data)
    {
        $transaction_raw_data = (array) $transaction_raw_data;
        $transaction_data = parent::formalizeData($transaction_raw_data);

        if (isset($transaction_raw_data['params']['id'])) {
            $transaction_data['native_id'] = $transaction_raw_data['params']['id'];
        }

        $transaction_data['order_id'] = $this->order_id;
        $transaction_data['amount'] = ifempty($transaction_raw_data['params']['amount'], '');
        $transaction_data['create_datetime'] = Format::timestamp2datetime($this->request->params['time']);

        $currency = $this->allowedCurrency();
        if (in_array('UZS', $currency)) {
            $currency_id = 'UZS';
        } else {
            $currency_id = reset($currency);
        }

        $transaction_data['currency_id'] = $currency_id;
        return $transaction_data;
    }

    /**
     * @param $type
     * @param array $transaction_data
     * @throws waException
     */
    protected function setRedirect($type, $transaction_data = array()) {
        $url = $this->getAdapter()->getBackUrl($type, $transaction_data);
        wa()->getResponse()->redirect($url);
    }

    /**
     * Обработка вызовов платежной системы.
     *
     * Проверяются параметры запроса, и при необходимости вызывается обработчик приложения.
     * Настройки плагина уже проинициализированы и доступны в коде метода.
     *
     *
     * @param array $request Данные запроса (массив $_REQUEST), полученного от платежной системы
     * @throws waPaymentException
     * @throws PaycomException
     * @throws waException
     * @return array Ассоциативный массив необязательных параметров результата обработки вызова:
     *     'redirect' => URL для перенаправления пользователя
     *     'template' => путь к файлу шаблона, который необходимо использовать для формирования веб-страницы, отображающей результат обработки вызова платежной системы;
     *                   укажите false, чтобы использовать прямой вывод текста
     *                   если не указано, используется системный шаблон, отображающий строку 'OK'
     *     'header'   => ассоциативный массив HTTP-заголовков (в форме 'header name' => 'header value'),
     *                   которые необходимо отправить в браузер пользователя после завершения обработки вызова,
     *                   удобно для случаев, когда кодировка символов или тип содержимого отличны от UTF-8 и text/html
     *
     *     Если указан путь к шаблону, возвращаемый результат в исходном коде шаблона через переменную $result variable;
     *     параметры, переданные методу, доступны в массиве $params.
     */
    protected function callbackHandler($request)
    {
        $this->initRequest();
        $this->config   = $this->getConfig();
        $this->response = new PaycomResponse($this->request);
        $this->merchant = new Merchant($this->config);
        $this->redirect = null;

        $this->transaction_data = $this->formalizeData($this->request);

        $app_payment_method = null;

        try {
            // authorize session
            $this->merchant->Authorize($this->request->id);

            // handle request
            switch ($this->request->method) {
                case 'CheckPerformTransaction':
                    $this->CheckPerformTransaction();
                    break;
                case 'CheckTransaction':
                    $this->CheckTransaction();
                    break;
                case 'CreateTransaction':
                    $this->CreateTransaction();
                    break;
                case 'PerformTransaction':
                    $app_payment_method = $this->PerformTransaction();
                    break;
                case 'CancelTransaction':
                    $app_payment_method = $this->CancelTransaction();
                    break;
                case 'ChangePassword':
                    $this->ChangePassword();
                    break;
                case 'GetStatement':
                    $this->GetStatement();
                    break;
                default:
                    $this->response->error(
                        PaycomException::ERROR_METHOD_NOT_FOUND,
                        'Method not found.',
                        $this->request->method
                    );
                    break;
            }
        } catch (PaycomException $exc) {
            if (waSystemConfig::isDebug()) {
                waLog::dump($this, 'paycom-exception.log');
                waLog::dump($exc, 'paycom-exception.log');
            }
        }

        if ($app_payment_method) {
            $this->execAppCallback($app_payment_method, $this->transaction_data);
        }

        return array(
            'template'    => $this->path.'/templates/result.html',
        );
    }

    /**
     * Возвращает URL запроса к платежной системе в зависимости от выбранного в настройках протокола подключения.
     *
     * @return string
     */
    protected function getEndpointUrl()
    {
        if ($this->TESTMODE) {
            $url = 'http://checkout.test.paycom.uz';
        } else {
            $url = 'https://checkout.paycom.uz';
		}
        return $url;
    }

    /**
     * @return array
     */
    private function getFormOptions()
    {
        $options = array(
            'accept-charset'        => 'utf-8',
        );

        return $options;

    }

    /**
     * @param $data
     * @return bool
     */
    private function verifySign($data)
    {
        
            $result = true;
        
        return $result;
    }

    /**
     * @throws PaycomException
     * @throws waException
     */
    private function CheckPerformTransaction()
    {
        $this->response->send(['allow' => true]);
    }

    /**
     * @throws PaycomException
     * @throws waException
     */
    private function CheckTransaction()
    {
        $transaction_model = new waTransactionModel();
        $transaction = $transaction_model->getByField('native_id', $this->request->params['id']);
        if (empty($transaction)) {
            $this->response->error(
                PaycomException::ERROR_TRANSACTION_NOT_FOUND,
                'Transaction not found.'
            );
        }
        else {
            $this->transaction_data = $transaction;
            // todo: Prepare and send found transaction

            $cancel_time = 0;

            if ($transaction['state'] < 0) {
                $cancel_time = intval(Format::datetime2timestamp($transaction['update_datetime'])) * 1000;
                $reason = intval($transaction['error']);
            }
            else {
                $reason = null;
            }

            if ((int) $transaction['state'] == 1) {
                $perform_time = 0;
            }
            else {
                $perform_time = intval(Format::datetime2timestamp($transaction['update_datetime'])) * 1000;
            }

            $this->response->send([
                'create_time'  => intval(Format::datetime2timestamp($transaction['create_datetime'])) * 1000,
                'perform_time' => $perform_time,
                'cancel_time'  => $cancel_time,
                'transaction'  => $transaction['native_id'],
                'state'        => (int) $transaction['state'],
                'reason'       => $reason,
            ]);
        }
    }


    /**
     * @throws PaycomException
     * @throws waException
     */
    private function CreateTransaction()
    {
        $transaction_model = new waTransactionModel();
        $transaction = $transaction_model->getByField('native_id', $this->request->params['id']);

        $transaction_order = $transaction_model->select('*')->where('order_id = ' .  intval($this->order_id) . ' AND state > 0 AND native_id <> "' . $this->request->params['id'] . '"' )->fetchAll();
        if (!empty($transaction_order)) {
            //foreach ($transaction_order as $o) {
            //    $transaction = $this->cancel($o, Transaction::REASON_EXECUTION_FAILED);
            //}

            $ex = new PaycomException(
                $this->request->id,
                array(
                    'ru' => 'Ошибка',
                    'en' => 'Error',
                    'uz' => 'Oshibka'
                ),
                -31099
            );

            $ex->send();
        }
        else {
            $this->transaction_data['state'] = Transaction::STATE_CREATED;



            if (empty($transaction)) {
                $this->transaction_data = $transaction = $this->saveTransaction($this->transaction_data);
            }
            else {
                $this->transaction_data = $transaction;
            }

            if ($transaction['state'] != Transaction::STATE_CREATED) { // validate transaction state
                $this->response->error(
                    PaycomException::ERROR_COULD_NOT_PERFORM,
                    'Transaction found, but is not active.'
                );
            }
            else { // if transaction found and active, send it as response
                $this->response->send([
                    'create_time' => $this->request->params['time'],
                    'transaction' => $transaction['native_id'],
                    'state'       => $transaction['state'],
                    'receivers'   => null,
                ]);
            }
        }

    }

    /**
     * @param $transaction
     * @return bool
     */
    private function isExpired($transaction)
    {
        return $this->state == Transaction::STATE_CREATED && Format::datetime2timestamp($transaction['create_datetime']) - time() > Transaction::TIMEOUT;
    }

    /**
     * Cancels transaction with the specified reason.
     * @param $transaction
     * @param int $reason cancelling reason.
     * @return array
     */
    public function cancel($transaction, $reason)
    {
        $transaction['update_datetime'] = Format::timestamp2datetime(Format::timestamp());

        if ($transaction['state'] == Transaction::STATE_COMPLETED) {
            // Scenario: CreateTransaction -> PerformTransaction -> CancelTransaction
            $transaction['state'] = Transaction::STATE_CANCELLED_AFTER_COMPLETE;
        } else {
            // Scenario: CreateTransaction -> CancelTransaction
            $transaction['state'] = Transaction::STATE_CANCELLED;
        }

        // set reason
        $transaction['error'] = $reason;

        $transaction_model = new waTransactionModel();
        $transaction_model->updateById($transaction['id'], $transaction);

        return $transaction;
    }

    /**
     * @throws PaycomException
     * @throws waException
     */
    private function PerformTransaction()
    {
        $transaction_model = new waTransactionModel();
        $transaction = $transaction_model->getByField('native_id', $this->request->params['id']);
        $app_payment_method = null;

        // if transaction not found, send error
        if (empty($transaction)) {
            $this->response->error(PaycomException::ERROR_TRANSACTION_NOT_FOUND, 'Transaction not found.');
        }
        else {
            switch ($transaction['state']) {
                case Transaction::STATE_CREATED: // handle active transaction
                    if ($this->isExpired($transaction)) { // if transaction is expired, then cancel it and send error
                        $this->cancel($transaction, Transaction::REASON_CANCELLED_BY_TIMEOUT);
                        $this->response->error(
                            PaycomException::ERROR_COULD_NOT_PERFORM,
                            'Transaction is expired.'
                        );
                    } else { // perform active transaction
                        // todo: Заказ оплачен
                        $app_payment_method = self::CALLBACK_PAYMENT;
                        $this->redirect = waAppPayment::URL_SUCCESS;

                        // todo: Mark transaction as completed
                        $perform_time                   = Format::timestamp();
                        $transaction['state']           = Transaction::STATE_COMPLETED;
                        $transaction['update_datetime'] = Format::timestamp2datetime($perform_time);
                        $transaction_model->updateById($transaction['id'], $transaction);

                        $this->response->send([
                            'transaction'  => $transaction['native_id'],
                            'perform_time' => intval($perform_time) * 1000,
                            'state'        => intval($transaction['state']),
                        ]);
                    }
                    break;

                case Transaction::STATE_COMPLETED: // handle complete transaction
                    // todo: If transaction completed, just return it
                    $this->response->send([
                        'transaction'  => $transaction['native_id'],
                        'perform_time' => intval(Format::datetime2timestamp($transaction['update_datetime'])) * 1000,
                        'state'        => intval($transaction['state']),
                    ]);
                    break;

                default:
                    // unknown situation
                    $this->response->error(
                        PaycomException::ERROR_COULD_NOT_PERFORM,
                        'Could not perform this operation.'
                    );
                    break;
            }

            $this->transaction_data = $transaction;
        }

        return $app_payment_method;
    }

    /**
     * @throws PaycomException
     * @throws waException
     */
    private function CancelTransaction()
    {
        $transaction_model = new waTransactionModel();
        $transaction = $transaction_model->getByField('native_id', $this->request->params['id']);
        $app_payment_method = null;

        // if transaction not found, send error
        if (empty($transaction)) {
            $this->response->error(PaycomException::ERROR_TRANSACTION_NOT_FOUND, 'Transaction not found.');
        }
        else {
            switch ($transaction['state']) {
                // if already cancelled, just send it
                case Transaction::STATE_CANCELLED:
                case Transaction::STATE_CANCELLED_AFTER_COMPLETE:
                    $this->response->send([
                        'transaction' => $transaction['native_id'],
                        'cancel_time' => intval(Format::datetime2timestamp($transaction['update_datetime'])) * 1000,
                        'state'       => (int) $transaction['state'],
                    ]);
                    break;

                // cancel active transaction
                case Transaction::STATE_CREATED:
                    // cancel transaction with given reason
                    $transaction = $this->cancel($transaction, $this->request->params['reason']);
                    // after $found->cancel(), cancel_time and state properties populated with data

                    $app_payment_method = self::CALLBACK_CANCEL;
                    $this->redirect = waAppPayment::URL_FAIL;

                    // send response
                    $this->response->send([
                        'transaction' => $transaction['native_id'],
                        'cancel_time' => intval(Format::datetime2timestamp($transaction['update_datetime'])) * 1000,
                        'state'       => (int) $transaction['state'],
                    ]);
                    break;

                case Transaction::STATE_COMPLETED:
                    // find order and check, whether cancelling is possible this order
                    $transaction = $this->cancel($transaction, $this->request->params['reason']);
                    // after $found->cancel(), cancel_time and state properties populated with data

                    $app_payment_method = self::CALLBACK_REFUND;
                    $this->redirect = waAppPayment::URL_SUCCESS;

                    $this->response->send([
                        'transaction' => $transaction['native_id'],
                        'cancel_time' => intval(Format::datetime2timestamp($transaction['update_datetime'])) * 1000,
                        'state'       => (int) $transaction['state'],
                    ]);

                    break;
            }
            $this->transaction_data = $transaction;
        }
        return $app_payment_method;
    }

    /**
     * @throws PaycomException
     */
    private function ChangePassword()
    {
        // validate, password is specified, otherwise send error
        if (!isset($this->request->params['password']) || !trim($this->request->params['password'])) {
            $this->response->error(PaycomException::ERROR_INVALID_ACCOUNT, 'New password not specified.', 'password');
        }

        // if current password specified as new, then send error
        if ($this->merchant->config['key'] == $this->request->params['password']) {
            $this->response->error(PaycomException::ERROR_INSUFFICIENT_PRIVILEGE, 'Insufficient privilege. Incorrect new password.');
        }

        // todo: Implement saving password into data store or file
        // example implementation, that saves new password into file specified in the configuration
        if (!file_put_contents($this->config['keyFile'], $this->request->params['password'])) {
            $this->response->error(PaycomException::ERROR_INTERNAL_SYSTEM, 'Internal System Error.');
        }

        // if control is here, then password is saved into data store
        // send success response
        $this->response->send(['success' => true]);
    }

    /**
     * @throws PaycomException
     */
    private function GetStatement()
    {
        // validate 'from'
        if (!isset($this->request->params['from'])) {
            $this->response->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period.', 'from');
        }

        // validate 'to'
        if (!isset($this->request->params['to'])) {
            $this->response->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period.', 'to');
        }

        // validate period
        if (1 * $this->request->params['from'] >= 1 * $this->request->params['to']) {
            $this->response->error(PaycomException::ERROR_INVALID_ACCOUNT, 'Incorrect period. (from >= to)', 'from');
        }

        // get list of transactions for specified period
        $transaction  = new Transaction();
        $transactions = $transaction->report($this->request->params['from'], $this->request->params['to']);

        // send results back
        $this->response->send(['transactions' => $transactions]);
    }

    /**
     * @return string
     * @throws waException
     */
    public static function getFeedbackControl()
    {
        $view = wa()->getView();
        return $view->fetch(waSystemConfig::getActive()->getRootPath() . '/wa-plugins/payment/paycom/' . 'templates/controls/feedbackControl.html');
    }
}
