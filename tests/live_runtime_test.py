"""Synthetic LIVE/TEST boundary tests. No provider transport is executed."""
import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest

PROJECT = Path(__file__).resolve().parents[1]
REALM = 'a' * 32
ACCOUNT = 'acct_' + 'SyntheticMerchant123'


class LiveRuntime(unittest.TestCase):
    def setUp(self):
        self.work = Path(tempfile.mkdtemp(prefix='sitesee-live-boundary-'))
        self.addCleanup(shutil.rmtree, self.work)
        self.root = self.work / 'private'
        shutil.copytree(PROJECT / '_private', self.root)
        self.root.chmod(0o700)
        (self.work / 'public').mkdir(mode=0o700)
        self.live = self.root / 'runtime/live'
        self.live.mkdir(mode=0o700, parents=True)
        (self.root / 'runtime').chmod(0o700)
        (self.live / 'data').mkdir(mode=0o700)
        (self.root / 'data').mkdir(mode=0o700, exist_ok=True)
        (self.root / 'data').chmod(0o700)
        self.env = dict(os.environ, SITESEE_REAL_ESTATE_SITE_URL='https://mode.example.test',
                        SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='isolated-legacy-pricing-key-' + '0' * 32,
                        SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED='1',
                        SITESEE_REAL_ESTATE_BOOKING_DB=str(self.root / 'data/bookings.sqlite'),
                        SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET='sk_' + 'test_' + '0' * 32,
                        SITESEE_REAL_ESTATE_STRIPE_TEST_WEBHOOK_SECRET='whsec_' + 'oldSynthetic' * 3)
        self.env.pop('SITESEE_APPLICATION_STAGE', None)
        self.json_file(self.root / 'booking-lifecycle.json',
                       dict(schema=1, stage='test', enabled=True, recipient='sales@re.sitesee.ai'))
        self.run_php("require $argv[1].'/server/booking-lifecycle.php'; $db=booking_db(); booking_communication_schema($db);")
        self.json_file(self.root / 'application-live.json',
                       dict(format=1, stage='LIVE', enabled=True, realm=REALM, stripe_account=ACCOUNT, initial_code_release='unified-test-'+'1'*12))
        self.json_file(self.root/'live-activation-journal.json', dict(state='active', realm=REALM, stripe_account=ACCOUNT, initial_code_release='unified-test-'+'1'*12))
        self.json_file(self.live / 'environment.json', dict(stage='LIVE', realm=REALM, values={
            'SITESEE_REAL_ESTATE_STRIPE_LIVE_SECRET': 'sk_' + 'live_' + '1' * 32,
            'SITESEE_REAL_ESTATE_STRIPE_LIVE_WEBHOOK_SECRET': 'whsec_' + 'newSynthetic' * 3,
            'SITESEE_REAL_ESTATE_PRICING_GATE_SECRET': 'isolated-live-pricing-key-' + '2' * 32,
        }))
        self.json_file(self.live / 'booking-lifecycle.json',
                       dict(schema=1, stage='live', enabled=True, recipient='booking-customer'))
        self.live_db = self.live / 'data/bookings.sqlite'
        with sqlite3.connect(self.live_db) as db:
            db.execute('CREATE TABLE application_realm (singleton INTEGER PRIMARY KEY CHECK(singleton=1), stage TEXT NOT NULL, realm TEXT NOT NULL, stripe_account TEXT NOT NULL)')
            db.execute('INSERT INTO application_realm VALUES (1,?,?,?)', ('LIVE', REALM, ACCOUNT))
        self.live_db.chmod(0o600)

    @staticmethod
    def json_file(path, value):
        path.write_text(json.dumps(value))
        path.chmod(0o600)

    def run_php(self, code, check=True):
        result = subprocess.run(['php', '-d', 'disable_functions=curl_exec', '-r', code, str(self.root)],
                                env=self.env, capture_output=True, text=True, timeout=20)
        if check:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result

    def test_default_is_test_and_environment_alone_cannot_activate(self):
        (self.root / 'application-live.json').unlink()
        (self.root / 'live-activation-journal.json').unlink()
        self.env['SITESEE_APPLICATION_STAGE'] = 'LIVE'
        result = self.run_php("require $argv[1].'/server/application.php'; try {site_application_bootstrap($argv[1]);echo 'ACCEPT';}catch(Throwable){echo site_runtime_mode().':REFUSED';}")
        self.assertEqual(result.stdout, 'TEST:REFUSED')

    def test_live_uses_its_own_empty_financial_ledger(self):
        result = self.run_php("require $argv[1].'/server/booking-store.php';$db=booking_db();echo json_encode([site_runtime_mode(),$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),booking_finance_available($db,str_repeat('a',32)),BOOKING_CONSENT_VERSION,booking_test_key()===getenv('SITESEE_REAL_ESTATE_STRIPE_TEST_SECRET')]);")
        self.assertEqual(json.loads(result.stdout), ['LIVE', 0, 0, 'live-card-reuse-v1', False])

    def test_wrong_database_account_refused_before_schema_or_file_writes(self):
        with sqlite3.connect(self.live_db) as db:
            db.execute("UPDATE application_realm SET stripe_account='acct_WrongMerchant123'")
        before = self.live_db.read_bytes()
        result = self.run_php("require $argv[1].'/server/booking-store.php';try{booking_db();echo 'ACCEPT';}catch(Throwable){echo 'REFUSED';}")
        self.assertEqual(result.stdout, 'REFUSED')
        self.assertEqual(before, self.live_db.read_bytes())
        with sqlite3.connect(self.live_db) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='bookings'").fetchone()[0], 0)

    def test_missing_database_binding_is_not_automatically_created(self):
        with sqlite3.connect(self.live_db) as db:
            db.execute('DROP TABLE application_realm')
        before = self.live_db.read_bytes()
        result = self.run_php("require $argv[1].'/server/booking-store.php';try{booking_db();echo 'ACCEPT';}catch(Throwable){echo 'REFUSED';}")
        self.assertEqual(result.stdout, 'REFUSED')
        self.assertEqual(before, self.live_db.read_bytes())

    def test_live_key_session_and_object_modes_are_strict(self):
        result = self.run_php("require $argv[1].'/server/application-mode.php';echo json_encode([site_runtime_key_valid('sk_'.'live_'.str_repeat('1',32)),site_runtime_key_valid('sk_'.'test_'.str_repeat('0',32)),site_runtime_session_id('cs_live_owned'),site_runtime_session_id('cs_test_old'),site_runtime_livemode()]);")
        self.assertEqual(json.loads(result.stdout), [True, False, True, False, True])

    def test_expected_stripe_merchant_and_readiness_are_required(self):
        result = self.run_php("require $argv[1].'/server/application-mode.php';$key='sk_'.'live_'.str_repeat('1',32);$out=[];foreach([['id'=>'acct_WrongMerchant123','charges_enabled'=>true,'details_submitted'=>true],['id'=>'"+ACCOUNT+"','charges_enabled'=>false,'details_submitted'=>true],['id'=>'"+ACCOUNT+"','charges_enabled'=>true,'details_submitted'=>true]] as $a){try{site_runtime_stripe_account($key,fn()=>$a);$out[]='ACCEPT';}catch(Throwable){$out[]='REFUSED';}}echo json_encode($out);")
        self.assertEqual(json.loads(result.stdout), ['REFUSED', 'REFUSED', 'ACCEPT'])

    def test_opposite_webhook_mode_or_signing_secret_cannot_verify(self):
        result = self.run_php("require $argv[1].'/server/booking-store.php';$event=['id'=>'evt_owned','type'=>'checkout.session.expired','livemode'=>true,'data'=>['object'=>['id'=>'cs_live_owned']]];$stamp=time();$out=[];foreach([['whsec_'.str_repeat('newSynthetic',3),true],['whsec_'.str_repeat('oldSynthetic',3),true],['whsec_'.str_repeat('newSynthetic',3),false]] as [$secret,$mode]){$event['livemode']=$mode;$raw=json_encode($event);$sig='t='.$stamp.',v1='.hash_hmac('sha256',$stamp.'.'.$raw,$secret);try{booking_verify_stripe_event($raw,$sig);$out[]='ACCEPT';}catch(Throwable){$out[]='REFUSED';}}echo json_encode($out);")
        self.assertEqual(json.loads(result.stdout), ['ACCEPT', 'REFUSED', 'REFUSED'])

    def test_live_webhook_requires_pinned_merchant_event_readback(self):
        result = self.run_php("require $argv[1].'/server/booking-store.php';$event=['id'=>'evt_owned','type'=>'checkout.session.expired','livemode'=>true,'data'=>['object'=>['id'=>'cs_live_owned','metadata'=>['site_application_realm'=>str_repeat('a',32)]]]];$out=[];foreach(['correct','wrong-id','wrong-object','other-account'] as $case){$copy=$event;if($case==='wrong-id')$copy['id']='evt_other';if($case==='wrong-object')$copy['data']['object']['id']='cs_live_other';if($case==='other-account')$copy['account']='acct_WrongMerchant123';try{booking_verify_live_event($event,fn()=>$copy);$out[]='ACCEPT';}catch(Throwable){$out[]='REFUSED';}}echo json_encode($out);")
        self.assertEqual(json.loads(result.stdout), ['ACCEPT', 'REFUSED', 'REFUSED', 'REFUSED'])

    def test_legacy_test_cookies_and_live_cookies_are_separate(self):
        result = self.run_php("require $argv[1].'/server/portal-session.php';portal_session_start();echo json_encode([session_name(),session_save_path()]);session_write_close();")
        name, path = json.loads(result.stdout)
        self.assertEqual(name, '__Host-sitesee_portal_live')
        self.assertEqual(path, str(self.live / 'data/portal-sessions'))

    def test_live_environment_alias_is_rejected(self):
        candidate = self.live / 'environment.json'
        original = self.work / 'outside-sentinel.json'
        candidate.rename(original)
        candidate.symlink_to(original)
        result = self.run_php("require $argv[1].'/server/application-mode.php';try{site_runtime_env('SITESEE_REAL_ESTATE_STRIPE_LIVE_SECRET');echo 'ACCEPT';}catch(Throwable){echo 'REFUSED';}")
        self.assertEqual(result.stdout, 'REFUSED')

    def test_preserved_test_reservations_and_uncertain_intervals_stay_busy(self):
        prior=self.root/'data/bookings.sqlite'
        with sqlite3.connect(prior) as db:
            db.execute('INSERT INTO booking_confirmations (reference,state,calendar_uid,planned_start,planned_end,event_json,created_at) VALUES (?,?,?,?,?,?,?)',('legacy','confirmed','calendar',100,200,'{}','2026-10-15T00:00:00Z'))
            db.execute("INSERT INTO booking_lifecycle_operations(operation_id,reference,revision,actor,action,state,payload_json,created_at) VALUES ('old-operation','legacy',1,'staff','reschedule','uncertain',?,?)",
                       (json.dumps({'old_interval':[100,200],'new_interval':[300,400]}),'2026-10-15T00:00:00Z'))
        result=self.run_php("require $argv[1].'/server/booking-lifecycle.php';$db=booking_db();echo json_encode(booking_lifecycle_busy($db,['busy'=>[]],'legacy')['busy']);")
        self.assertEqual(json.loads(result.stdout),[[100,200],[100,200],[300,400]])
        # Forced TEST recovery must also see LIVE's uncertain holds.
        with sqlite3.connect(self.live_db) as db:
            db.execute("INSERT INTO booking_lifecycle_operations(operation_id,reference,revision,actor,action,state,payload_json,created_at) VALUES ('new-operation','new',1,'staff','reschedule','uncertain',?,?)",
                       (json.dumps({'old_interval':[500,600],'new_interval':[700,800]}),'2026-10-15T00:00:00Z'))
        result=self.run_php("define('SITESEE_REQUEST_MODE','TEST');require $argv[1].'/server/booking-lifecycle.php';$db=booking_db();echo json_encode(booking_lifecycle_busy($db,['busy'=>[]],'legacy')['busy']);")
        self.assertEqual(json.loads(result.stdout),[[500,600],[700,800]])

    def test_fixed_test_staff_recovery_links_never_switch_to_live_actions(self):
        result=self.run_php("define('SITESEE_REQUEST_MODE','TEST');require $argv[1].'/views/application-shell.php';echo site_application_shell('staff','Booking Review','<a href=\"staff-bookings.php?reference=OLD\">Open</a><p>staff-bookings.php is saved customer text</p>',true);")
        self.assertIn('TEST ACCESS',result.stdout)
        self.assertIn('href="staff-test-bookings.php?reference=OLD"',result.stdout)
        self.assertIn('href="/staff-test-production.php"',result.stdout)
        self.assertIn('staff-bookings.php is saved customer text',result.stdout)
        self.assertNotIn('href="/staff-bookings.php"',result.stdout)

    def test_test_recovery_refuses_intermediate_live_calendar_alias(self):
        outside=self.work/'preserved-runtime'
        (self.root/'runtime').rename(outside)
        (self.root/'runtime').symlink_to(outside,target_is_directory=True)
        before=(outside/'live/data/bookings.sqlite').read_bytes()
        result=self.run_php("define('SITESEE_REQUEST_MODE','TEST');require $argv[1].'/server/booking-lifecycle.php';try{booking_lifecycle_busy(booking_db(),['busy'=>[]]);echo 'ACCEPT';}catch(Throwable){echo 'REFUSED';}")
        self.assertEqual(result.stdout,'REFUSED')
        self.assertEqual(before,(outside/'live/data/bookings.sqlite').read_bytes())

    def test_test_pricing_links_retire_without_creating_live_orders(self):
        for name in ['pricing-approve.php','pricing-confirm.php','application-entry.php']:
            shutil.copyfile(PROJECT/'public'/name,self.work/'public'/name)
        self.env['SITESEE_APPLICATION_PRIVATE_ROOT']=str(self.root)
        for name in ['pricing-approve.php','pricing-confirm.php']:
            result=self.run_php("$_SERVER['DOCUMENT_ROOT']=dirname($argv[1]).'/public';$_SERVER['SCRIPT_FILENAME']=$_SERVER['DOCUMENT_ROOT'].'/"+name+"';require $_SERVER['SCRIPT_FILENAME'];")
            self.assertIn('This TEST pricing link is retired',result.stdout)
        with sqlite3.connect(self.live_db) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='bookings'").fetchone()[0],0)

    def test_new_live_links_and_operation_keys_are_different_from_test(self):
        result = self.run_php("require $argv[1].'/server/application-mode.php';echo json_encode([site_runtime_link_path('/booking-pay.php?reference=ABC'),site_runtime_link_path('/manage-appointment.php#secret'),site_runtime_idempotency('sitesee-refund-test-immutable'),site_runtime_payment_body(['mode'=>'payment'])]);")
        link, manage, key, body = json.loads(result.stdout)
        self.assertEqual(link, '/booking-live-pay.php?reference=ABC')
        self.assertEqual(manage, '/manage-live-appointment.php#secret')
        self.assertEqual(key, 'sitesee-refund-live-immutable')
        self.assertEqual(body['metadata[site_application_realm]'], REALM)
        self.assertEqual(body['payment_intent_data[metadata][site_application_realm]'], REALM)

    def test_current_live_display_does_not_claim_test_or_no_charge(self):
        result = self.run_php("require $argv[1].'/views/application-shell.php';echo site_application_shell('customer','My Orders','<p>Property TEST Name remains exact</p>',true);")
        self.assertNotIn('TEST ACCESS', result.stdout)
        self.assertNotIn('payments remain in test mode', result.stdout)
        self.assertIn('Property TEST Name remains exact', result.stdout)

    def test_existing_worker_diagnoses_both_ledgers_without_provider_calls(self):
        # Both existing schemas are initialized in disposable storage only.
        self.run_php("require $argv[1].'/server/booking-lifecycle.php';$db=booking_db();booking_communication_schema($db);")
        result = subprocess.run(['php', str(self.root/'server/booking-lifecycle-reconcile.php'), '--diagnose'],
                                env=self.env, capture_output=True, text=True, timeout=30)
        self.assertEqual(result.returncode, 0, result.stdout+result.stderr)
        self.assertEqual(result.stdout.count('CLI, database and worker selection: PASS.'), 2)
        self.assertNotIn('Financial ', result.stdout)


if __name__ == '__main__':
    unittest.main()
