"""Проверка формы без внешних запросов и без отправки реальных заявок."""
import argparse
import json
import hashlib
import os
from pathlib import Path
import socket
import subprocess
import sys
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php', help='Путь к PHP CLI')
    parser.add_argument('--browser-channel', default='msedge' if os.name == 'nt' else 'chromium', help='msedge, chrome или chromium')
    parser.add_argument('--output', type=Path, default=ROOT/'test-results/browser')
    args = parser.parse_args()
    OUT = args.output.resolve()
    OUT.mkdir(parents=True, exist_ok=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    url = f'http://127.0.0.1:{port}'
    report = {'checks': [], 'errors': [], 'external_requests': []}
    log = (OUT / 'php-test-server.log').open('w', encoding='utf-8')
    process = subprocess.Popen([args.php, '-S', f'127.0.0.1:{port}', '-t', str(ROOT)], stdout=log, stderr=log)
    try:
        for _ in range(40):
            try:
                urllib.request.urlopen(url, timeout=1).close()
                break
            except Exception:
                time.sleep(.2)
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True, **({} if args.browser_channel == 'chromium' else {'channel': args.browser_channel}))
            try:
                for width, height in [(1440, 1000), (390, 844), (320, 800)]:
                    page = browser.new_page(viewport={'width': width, 'height': height})
                    page.on('pageerror', lambda err: report['errors'].append(str(err)))

                    def block_external(route):
                        if not route.request.url.startswith(url + '/'):
                            report['external_requests'].append(route.request.url)
                            route.abort()
                        else:
                            route.continue_()

                    page.route('**/*', block_external)
                    page.goto(url)
                    page.wait_for_function("document.querySelector('.demo-note').textContent.includes('недоступен')")
                    page.locator('[name=phone]').fill('+7 (999) 123-45-67')
                    page.locator('[name=city]').fill('Тестовый город')
                    assert not page.locator('[name=consent]').is_checked()
                    assert page.locator('[name=consent]').evaluate('(e)=>e.required')
                    page.locator('[name=consent]').check()
                    page.locator('#lead-form [type=submit]').click()
                    page.wait_for_function("document.querySelector('#lead-form').dataset.state==='error'")
                    assert page.locator('[name=phone]').input_value()
                    report['checks'].append(f'{width}: сервер без конфигурации не принимает заявку')

                    calls = []
                    scenario = {'status': 422}

                    def fake_api(route):
                        if route.request.method == 'GET':
                            route.fulfill(status=200, json={'csrf': 'a'*64,
                                'consent_text': 'Я даю согласие на обработку персональных данных.',
                                'consent_version': '2026-11-17-v1',
                                'consent_url': url+'/consent-2026-11-17.html',
                                'consent_sha256': hashlib.sha256((ROOT/'consent-2026-11-17.html').read_bytes()).hexdigest()})
                        else:
                            calls.append(route.request.post_data_buffer.decode('utf-8', errors='replace'))
                            if scenario['status'] == 422:
                                route.fulfill(status=422, json={'error': 'Тестовая ошибка: проверьте данные'})
                            elif scenario['status'] == 0:
                                route.abort('failed')
                            else:
                                import re
                                key = re.search(r'name="idempotency_key"\r\n\r\n([a-f0-9]{32})', calls[-1]).group(1)
                                route.fulfill(status=202, json={'id': key, 'status': 'received'})

                    page.route('**/api/lead.php', fake_api)
                    page.reload()
                    page.locator('[name=consent]').wait_for(state='visible')
                    page.locator('[name=phone]').fill('+7 (999) 123-45-67')
                    page.locator('[name=city]').fill('Тестовый город')
                    if page.locator('[name=email]').count():
                        page.locator('[name=email]').fill('test@example.invalid')
                    assert not page.locator('[name=consent]').is_checked()
                    with page.expect_popup() as popup:
                        page.locator('.consent-control a').click()
                    document = popup.value
                    document.wait_for_load_state()
                    assert document.locator('h1').inner_text() == 'Согласие на обработку персональных данных'
                    assert document.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
                    document.screenshot(path=str(OUT/f'consent-{width}.png'))
                    document.close()
                    assert not page.locator('[name=consent]').is_checked()
                    page.locator('#project-file').set_input_files({'name': 'test.pdf', 'mimeType': 'application/pdf', 'buffer': b'%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF'})
                    submit = page.locator('#lead-form [type=submit]')
                    submit.click()
                    assert len(calls)==0
                    report['checks'].append(f'{width}: согласие не отмечено заранее, без него нет отправки; документ открывается отдельно')
                    page.locator('[name=consent]').check()
                    submit.click()
                    page.wait_for_function("document.querySelector('#form-status').textContent.includes('Тестовая ошибка')")
                    assert page.locator('#project-file').evaluate('(e)=>e.files.length') == 1
                    assert page.locator('[name=city]').input_value() == 'Тестовый город'
                    assert submit.is_enabled()
                    report['checks'].append(f'{width}: ошибка 422 сохраняет поля и вложения')
                    scenario['status'] = 0
                    submit.click()
                    page.wait_for_function("document.querySelector('#lead-form').dataset.state==='error' && document.querySelector('#lead-form [type=submit]').disabled===false")
                    assert page.locator('#project-file').evaluate('(e)=>e.files.length') == 1
                    report['checks'].append(f'{width}: сетевой сбой сохраняет данные')
                    scenario['status'] = 202
                    submit.click()
                    page.wait_for_function("document.querySelector('#lead-form').dataset.state==='received'")
                    assert submit.is_disabled()
                    import re
                    keys = [re.search(r'name="idempotency_key"\r\n\r\n([a-f0-9]{32})', c).group(1) for c in calls]
                    assert len(set(keys)) == 1 and len(keys) == 3
                    assert 'name="files[]"; filename="test.pdf"' in calls[-1]
                    assert 'name="email"' in calls[-1]
                    assert 'name="consent_version"\r\n\r\n2026-11-17-v1' in calls[-1]
                    assert hashlib.sha256((ROOT/'consent-2026-11-17.html').read_bytes()).hexdigest() in calls[-1]
                    success = page.locator('#form-status').inner_text()
                    assert 'ожидает обработки' in success and 'CRM' not in success
                    assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
                    report['checks'].append(f'{width}: повтор использует один ID, прием не выдается за доставку')
                    page.locator('#form-status').scroll_into_view_if_needed()
                    page.screenshot(path=str(OUT/f'form-{width}.png'))
                    page.close()
            finally:
                browser.close()
        assert not report['errors'], report['errors']
        assert not report['external_requests'], report['external_requests']
        (OUT/'browser-results.json').write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding='utf-8')
        print(json.dumps(report, ensure_ascii=False, indent=2))
    finally:
        process.terminate()
        process.wait(timeout=10)
        log.close()


if __name__ == '__main__':
    main()
