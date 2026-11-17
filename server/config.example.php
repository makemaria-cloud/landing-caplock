<?php
declare(strict_types=1);
return [
    'enabled' => false,
    'consent_approved' => false,
    'consent_text' => '',
    'origin' => 'https://caplock.dayagroup.ru',
    'document_root' => '/home/ACCOUNT/caplock.dayagroup.ru/htdocs',
    'storage' => '/home/ACCOUNT/caplock-private/queue',
    'rate_key' => '', // Случайные 32+ байта, не пароль SMTP.
    'retention_days' => 7,
    'dedup_days' => 30,
    'max_jobs' => 500,
    'crm' => [
        'portal' => 'https://bitrix.opalubka.vip:8443',
        'webhook' => '', // HTTPS URL вида https://PORTAL/rest/USER/TOKEN/.
        'files_field' => '', // Множественное UF типа file.
        'id_field' => '', // Отдельное строковое UF для идентификатора заявки.
        'address_field' => 'UF_CRM_1667293827361', // Адрес (DaData) лида, string; найден в выгрузке 11.11.2026.
        'source_value' => null, // Согласованное значение UF_CRM_1703676529460, не выдумывать ID enum.
        'assigned_by_id' => 32427, // Екатерина Буркова по выгрузке; перед запуском проверить активность.
        'required_fields' => [],
    ],
    'smtp' => [
        'host' => '', // Только подтвержденный сервер почты, не обязательно Hostland.
        'port' => 587,
        'security' => 'tls', // tls = STARTTLS:587, ssl = implicit TLS:465.
        'username' => '',
        'password' => '',
        'from' => '',
        'to' => 'zayavka@dayagroup.ru',
    ],
];
