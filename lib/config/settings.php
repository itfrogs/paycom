<?php
return array(
    'KASSA_ID' => array(
        'value'        => '',
        'title'        => 'KASSA_ID',
        'description'  => 'ID кассы в системе paycom',
        'control_type' => 'input',
    ),
    'secret_key'      => array(
        'value'        => '',
        'title'        => 'Secret key',
        'description'  => 'Секретный ключ',
        'control_type' => 'input',
    ),
    'feedback' => array(
        'title' => 'Техническая поддержка',
        'description' => 'Перейдите по ссылке чтобы связаться с разработчиком',
        'control_type' => waHtmlControl::CUSTOM . ' ' . 'paycomPayment::getFeedbackControl',
        'subject' => 'info_settings',
    ),

);
