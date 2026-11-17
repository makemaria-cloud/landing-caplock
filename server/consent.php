<?php
declare(strict_types=1);
namespace Caplock;

const CONSENT_VERSION = '2026-11-17-v1';
const CONSENT_PATH = '/consent-2026-11-17.html';
const CONSENT_LABEL = 'Я даю согласие на обработку персональных данных.';

function consentDocument(array $c): array
{
    $path = rtrim($c['document_root'], '/\\') . CONSENT_PATH;
    $html = is_file($path) ? file_get_contents($path) : false;
    if ($html === false || !str_contains($html, 'name="consent-version" content="' . CONSENT_VERSION . '"')) {
        fail(503, 'Прием заявок пока недоступен.');
    }
    return ['version'=>CONSENT_VERSION, 'url'=>$c['origin'] . CONSENT_PATH,
        'document_sha256'=>hash('sha256', $html), 'checkbox_text'=>CONSENT_LABEL,
        'document_html'=>$html];
}
function verifyConsent(array $post, array $document): void
{
    if (($post['consent'] ?? '') !== '1'
        || !is_string($post['consent_version'] ?? null)
        || !is_string($post['consent_sha256'] ?? null)
        || !hash_equals($document['version'], $post['consent_version'])
        || !hash_equals($document['document_sha256'], $post['consent_sha256'])) {
        fail(422, 'Условия согласия обновились. Обновите страницу и подтвердите согласие заново.');
    }
}
