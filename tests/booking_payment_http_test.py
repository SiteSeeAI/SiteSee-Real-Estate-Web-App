import contextlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.error
import urllib.parse
import urllib.request

PROJECT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('SITESEE_TEST_PHP') or shutil.which('php') or '/workspace/scratch/0620b17ff5df/php-bin/php'


class PaymentHTTPTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not Path(PHP).is_file():
            raise unittest.SkipTest('PHP is required for HTTP controller checks.')
        cls.temp = tempfile.TemporaryDirectory()
        cls.base = Path(cls.temp.name)
        shutil.copytree(PROJECT / '_private', cls.base / '_private')
        (cls.base / 'public').mkdir()
        config = cls.base / '_private/booking-checkout.json'
        config.write_text(json.dumps({'stage': 'TEST', 'enabled': True, 'publishable_key': 'pk_test_' + 'p' * 24}))
        config.chmod(0o600)
        cls.env = dict(os.environ, SITESEE_REAL_ESTATE_SITE_URL='https://re.sitesee.ai',
                       SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED='1',
                       SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='q' * 48,
                       SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET='sk_test_' + 'a' * 24,
                       SITESEE_REAL_ESTATE_BOOKING_DB=str(cls.base / 'bookings.sqlite'))
        seed = cls.base / 'seed.php'
        seed.write_text("""<?php
require __DIR__.'/_private/server/booking-store.php';
$db=booking_db();
echo booking_capture($db,['action'=>'request_appointment','market'=>'residential',
'details'=>['email'=>'agent@example.test','street'=>'<script>bad()</script>','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703'],
'appointment'=>['date'=>'2026-10-15','time'=>'09:00','windowEnd'=>'11:00','windowMinutes'=>120],
'quote'=>['totalCents'=>24500,'platformMonthlyCents'=>0,'packageCents'=>0,'lines'=>[]]],'ABCDEF0099',true);
""")
        cls.token = subprocess.check_output([PHP, str(seed)], env=cls.env).decode().strip()
        router = cls.base / 'router.php'
        router.write_text("<?php require __DIR__.'/_private/server/booking-pay.php';")
        with contextlib.closing(socket.socket()) as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        cls.url = 'http://127.0.0.1:' + str(port) + '/booking-pay.php'
        cls.process = subprocess.Popen([PHP, '-S', '127.0.0.1:' + str(port), '-t', str(cls.base / 'public'), str(router)],
                                       env=cls.env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        for _ in range(50):
            try:
                cls.opener.open(cls.url, timeout=1)
                break
            except urllib.error.HTTPError:
                break
            except urllib.error.URLError:
                time.sleep(.03)
        else:
            cls.process.terminate()
            raise RuntimeError('Local PHP test server could not start.')

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate()
        cls.process.wait(timeout=5)
        cls.temp.cleanup()

    def setUp(self):
        result = self.opener.open(self.url + '?reference=ABCDEF0099&token=' + self.token)
        self.html = result.read().decode()
        self.headers = result.headers
        self.cookie = result.headers['Set-Cookie'].split(';')[0]
        self.csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', self.html).group(1)

    def post(self, data, origin=None):
        headers = {'Accept': 'application/json', 'Cookie': self.cookie}
        if origin:
            headers['Origin'] = origin
        request = urllib.request.Request(self.url, urllib.parse.urlencode(data).encode(), headers)
        try:
            result = self.opener.open(request)
        except urllib.error.HTTPError as error:
            result = error
        return result.status, json.loads(result.read())

    def test_branded_html_is_private_and_escapes_customer_input(self):
        self.assertIn('Your Booking Deposit', self.html)
        self.assertIn('800 222-2053', self.html)
        self.assertIn('&lt;script&gt;bad()&lt;/script&gt;', self.html)
        self.assertNotIn('sk_test_', self.html)
        self.assertIn('no-store', self.headers['Cache-Control'])
        cookie = self.headers['Set-Cookie'].lower()
        self.assertIn('secure', cookie)
        self.assertIn('httponly', cookie)
        self.assertIn('samesite=lax', cookie)
        csp = self.headers['Content-Security-Policy']
        self.assertIn("script-src 'nonce-", csp)
        self.assertNotIn("script-src 'unsafe-inline'", csp)
        self.assertIn('https://checkout.stripe.com', csp)
        self.assertIn("frame-ancestors 'none'", csp)

    def test_csrf_consent_and_cross_origin_rejected_before_stripe(self):
        data = dict(action='checkout', reference='ABCDEF0099', token=self.token, card_consent='yes')
        self.assertEqual(self.post(data)[0], 403)
        data['csrf'] = self.csrf
        self.assertEqual(self.post(data, 'https://untrusted.example')[0], 403)
        del data['card_consent']
        status, result = self.post(data)
        self.assertEqual(status, 409)
        self.assertIn('agree', result['error'])

    def test_status_requires_authenticated_session_and_never_claims_paid(self):
        data = dict(action='payment_status', reference='ABCDEF0099', csrf=self.csrf)
        self.assertEqual(self.post(data), (200, {'paid': False}))
        data['token'] = '0' * 64
        self.assertEqual(self.post(data)[0], 404)

    def test_success_query_is_not_proof_of_payment(self):
        request = urllib.request.Request(self.url + '?result=success&reference=ABCDEF0099', headers={'Cookie': self.cookie})
        html = self.opener.open(request).read().decode()
        self.assertIn('Checking Your Payment.', html)
        self.assertNotIn('Your Deposit Is Recorded.', html)


if __name__ == '__main__':
    unittest.main()
