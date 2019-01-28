<?php

namespace Paycom;

/**
 * Class Merchant
 * @package Paycom
 */
class Merchant
{
    /**
     * @var
     */
    public $config;

    /**
     * Merchant constructor.
     * @param $config
     */
    public function __construct($config)
    {
        $this->config = $config;

        // read key from key file
        if ($this->config['keyFile']) {
            $this->config['key'] = trim(file_get_contents($this->config['keyFile']));
        }
    }

    /**
     * @param $request_id
     * @return bool
     * @throws PaycomException
     */
    public function Authorize($request_id)
    {
        $headers = getallheaders();

        if (!$headers || !isset($headers['Authorization']) ||
            !preg_match('/^\s*Basic\s+(\S+)\s*$/i', $headers['Authorization'], $matches) ||
            base64_decode($matches[1]) != $this->config['login'] . ":" . $this->config['key']
        ) {
            $ex = new PaycomException(
                $request_id,
                array(
                    'ru' => 'Ошибка',
                    'en' => 'Error',
                    'uz' => 'Oshibka'
                ),
                PaycomException::ERROR_INSUFFICIENT_PRIVILEGE
            );

            $ex->send();

            throw new PaycomException(
                $request_id,
                'Insufficient privilege to perform this method.',
                PaycomException::ERROR_INSUFFICIENT_PRIVILEGE
            );

        }

        return true;
    }
}
