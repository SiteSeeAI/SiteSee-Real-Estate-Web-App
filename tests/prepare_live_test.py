"""Private preparation/activation exercises with synthetic providers and ledgers."""
import importlib.util
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('prepare_live',ROOT/'tools/prepare-live.py')
tool=importlib.util.module_from_spec(spec)
spec.loader.exec_module(tool)


class Preparation(unittest.TestCase):
    def setUp(self):
        self.tmp=Path(tempfile.mkdtemp(prefix='sitesee-prepare-live-'))
        self.addCleanup(shutil.rmtree,self.tmp)
        self.root=self.tmp/'private'
        shutil.copytree(ROOT/'_private',self.root)
        self.root.chmod(0o700)
        (self.root/'data').mkdir(mode=0o700,exist_ok=True)
        (self.root/'data').chmod(0o700)
        self.credentials={'secret':'sk_'+'live_'+'0'*32,'publishable':'pk_'+'live_'+'1'*32,'webhook':'whsec_'+'2'*32}
        self.account='acct_SyntheticMerchant123'
        self.sms={'provider':'twilio-verify','stage':'TEST','enabled':True,'allowed_numbers':['+12025550123'],
                  'account_sid':'AC'+'0'*32,'auth_token':'1'*32,'service_sid':'VA'+'2'*32}
        self.put('portal-sms.json',self.sms)
        graph=self.tmp/'.sitesee-graph-mail.json'
        self.put(graph,{'client_id':'125a86f5-2a29-4b1e-8b8a-26026e3fa59c','tenant_id':'10000000-0000-0000-0000-000000000000','client_secret':'synthetic-not-a-provider-secret'})
        self.put('microsoft-calendar.json',{'schema':1,'stage':'test','mailbox':'sales@re.sitesee.ai','application_id':'125a86f5-2a29-4b1e-8b8a-26026e3fa59c','calendar_id':'AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0AhxzFTH7h2UC_-_f2aEjbcQAAAAA0UAAA',
                                         'graph_credentials':str(graph),'scheduling_enabled':False,'invitations_enabled':False})
        self.put('microsoft-scheduling.json',{'schema':1,'stage':'test','provider':'microsoft','enabled':True,'confirmation_stage':'test',
                                           'confirmation_enabled':True,'invitations_enabled':True,'test_recipient_email':'sales@re.sitesee.ai',
                                           'calendar_uid':'microsoft:AAkALgAAAAAAHYQDEapmEc2byACqAC-EWg0AhxzFTH7h2UC_-_f2aEjbcQAAAAA0UAAA'})
        self.put('booking-mail.json',{'stage':'test','enabled':True,'sender':'sales@re.sitesee.ai','graph_credentials':str(graph),'test_recipient_email':'sales@re.sitesee.ai'})
        self.put('zoho-crm.json',{'client_id':'synthetic','client_secret':'synthetic','refresh_token':'synthetic','org_id':'123','user_id':'456',
                                 'api_domain':'https://www.zohoapis.com','accounts_domain':'https://accounts.zoho.com','sync_mode':'api','original_sync_mode':'api','sync_reviewed_at':1,'owner_verified_at':1})
        self.db=self.root/'data/bookings.sqlite'
        with sqlite3.connect(self.db) as db:
            for name,schema in tool.IDENTITIES.items():
                db.execute('CREATE TABLE '+name+' ('+schema+')')
            db.execute("INSERT INTO portal_accounts VALUES ('account','customer@example.test',1,0)")
            db.execute("INSERT INTO portal_profiles VALUES ('account','First','Last','Company','+12025550123',1)")
            db.execute("INSERT INTO portal_phone_identities VALUES ('+12025550123','account','verified','revision',1)")
            db.execute("INSERT INTO vendor_accounts VALUES ('vendor','Vendor','+12025550124','revision',1,1,1)")
            db.execute('CREATE TABLE payment_sentinel (provider_id TEXT, credit INTEGER)')
            db.execute("INSERT INTO payment_sentinel VALUES ('pi_test_preserve',20000)")
        self.db.chmod(0o600)
        self.original=self.db.read_bytes()
        self.calls=[]
        self.env=dict(os.environ,SITESEE_REAL_ESTATE_SITE_URL=tool.SITE)
        self.env.pop('SITESEE_APPLICATION_STAGE',None)
        self.release='unified-test-'+'1'*12
        self.public=self.tmp/'public';self.public.mkdir(mode=0o755)
        names=['application-entry.php','booking-live-webhook.php','booking-live-pay.php','manage-live-appointment.php','pricing-live-approve.php','pricing-live-confirm.php','staff-test-bookings.php','staff-test-production.php','staff-test-vendors.php']
        files={'private/server/'+p.name:tool.digest(p.read_bytes()) for p in (self.root/'server').glob('*.php')}
        for name in names:
            shutil.copyfile(ROOT/'public'/name,self.public/name)
            files['public/'+name]=tool.digest((self.public/name).read_bytes())
        self.put('unified-release.json',{'release':self.release,'commit':'1'*40,'stage':'TEST','default_runtime_mode':'TEST','runtime_modes':['TEST','LIVE'],'files':files})
        self.patch_public=patch.object(tool,'PUBLIC',self.public);self.patch_public.start();self.addCleanup(self.patch_public.stop)

    def put(self,path,value):
        path=path if isinstance(path,Path) else self.root/path
        path.write_bytes(tool.encoded(value));path.chmod(0o600)

    def provider(self,url,authorization):
        self.calls.append(url)
        if url.endswith('/v1/account'):
            return {'id':self.account,'charges_enabled':True,'details_submitted':True}
        if '/webhook_endpoints?' in url:
            return {'has_more':False,'data':[{'id':'we_synthetic','url':tool.SITE+'/booking-live-webhook.php','livemode':True,'status':'enabled','api_version':tool.VERSION,'enabled_events':list(tool.EVENTS)}]}
        if '/Accounts/' in url:
            return {'sid':self.sms['account_sid'],'type':'Full','status':'active'}
        if '/Services/' in url:
            return {'sid':self.sms['service_sid'],'account_sid':self.sms['account_sid']}
        raise AssertionError('Unexpected provider URL')

    def prepare(self):
        return tool.prepare(self.root,self.credentials,self.provider)

    def check(self):
        with patch.dict(os.environ,self.env,clear=True):
            return tool.check_prepared(self.root,shutil.which('php'),self.provider)

    def activate(self):
        with patch.dict(os.environ,self.env,clear=True):
            return tool.activate(self.root,self.release,shutil.which('php'),self.provider)

    def test_prepare_keeps_test_and_copies_only_identity(self):
        result=self.prepare()
        self.assertFalse(result['financial_data_copied'])
        self.assertFalse((self.root/'application-live.json').exists())
        self.assertEqual(self.original,self.db.read_bytes())
        live=self.root/'runtime/live/data/bookings.sqlite'
        with sqlite3.connect(live) as db:
            self.assertEqual(db.execute('SELECT COUNT(*) FROM portal_accounts').fetchone(),(1,))
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sqlite_master WHERE name='payment_sentinel'").fetchone(),(0,))
        self.assertEqual(self.prepare()['identity_counts'],result['identity_counts'])
        self.check()
        self.assertEqual(self.original,self.db.read_bytes())

    def test_activation_is_explicit_idempotent_and_preserves_financial_history(self):
        self.prepare(); self.check()
        self.assertEqual(self.activate()['result'],'LIVE ACTIVATED')
        journal=json.loads((self.root/'live-activation-journal.json').read_bytes())
        self.assertEqual(journal['state'],'active')
        before=(self.root/'runtime/live/data/bookings.sqlite').read_bytes()
        self.assertIn('ALREADY ACTIVE',self.activate()['result'])
        self.assertEqual(before,(self.root/'runtime/live/data/bookings.sqlite').read_bytes())
        self.assertEqual(self.original,self.db.read_bytes())

    def test_wrong_mode_and_unknown_edits_are_preserved(self):
        wrong=self.credentials|{'secret':'sk_'+'test_'+'0'*32}
        with self.assertRaises(tool.Stop): tool.prepare(self.root,wrong,self.provider)
        self.assertEqual(self.calls,[])
        self.prepare()
        edited=self.root/'runtime/live/booking-mail.json'
        self.put(edited,{'unknown':'edit'})
        before=edited.read_bytes()
        with self.assertRaises(tool.Stop): self.prepare()
        self.assertEqual(before,edited.read_bytes())
        with self.assertRaises(tool.Stop): self.check()
        self.assertFalse((self.root/'application-live.json').exists())

    def test_wrong_merchant_and_trial_account_block_preparation(self):
        for wrong in ['stripe','twilio']:
            def read(url,authorization):
                value=self.provider(url,authorization)
                if wrong=='stripe' and url.endswith('/v1/account'):value['charges_enabled']=False
                if wrong=='twilio' and '/Accounts/' in url:value['type']='Trial'
                return value
            with self.assertRaises(tool.Stop):tool.prepare(self.root,self.credentials,read)
            self.assertFalse((self.root/'runtime/live').exists())

    def test_exact_dashboard_webhook_version_is_separate_from_checkout_version(self):
        self.assertEqual(tool.VERSION,'2026-03-25.dahlia')
        self.assertIn("BOOKING_CHECKOUT_API_VERSION = '2026-08-26.dahlia'",(ROOT/'_private/server/booking-checkout.php').read_text())
        def changed(url,authorization):
            result=self.provider(url,authorization)
            if '/webhook_endpoints?' in url:result['data'][0]['api_version']='2026-09-30.endive'
            return result
        with self.assertRaises(tool.Stop):tool.prepare(self.root,self.credentials,changed)
        self.assertFalse((self.root/'runtime/live').exists())

    def test_activation_during_provider_reads_or_missing_marker_cannot_reset_live(self):
        def race(url,authorization):
            result=self.provider(url,authorization)
            if '/Services/' in url:self.put('live-activation-journal.json',{'state':'active'})
            return result
        with self.assertRaises(tool.Stop):tool.prepare(self.root,self.credentials,race)
        self.assertFalse((self.root/'runtime/live').exists())
        self.assertEqual(json.loads((self.root/'live-activation-journal.json').read_bytes()),{'state':'active'})
        with self.assertRaises(tool.Stop):self.prepare()
        self.assertEqual(self.original,self.db.read_bytes())

    def test_activation_refuses_code_edit_after_verified_install(self):
        self.prepare();self.check()
        source=self.root/'server/application-mode.php'
        source.write_bytes(source.read_bytes()+b'\n// unknown deployed edit\n')
        before=source.read_bytes()
        with self.assertRaisesRegex(tool.Stop,'Installed code differs'):self.activate()
        self.assertEqual(source.read_bytes(),before)
        self.assertFalse((self.root/'application-live.json').exists())

    def test_no_existing_live_payment_can_be_overwritten(self):
        self.prepare();self.check()
        live=self.root/'runtime/live/data/bookings.sqlite'
        with sqlite3.connect(live) as db:
            db.execute('CREATE TABLE live_money (amount INTEGER)')
            db.execute('INSERT INTO live_money VALUES (123)')
            db.commit()
            before=list(db.iterdump())
        with self.assertRaises(tool.Stop):self.activate()
        with sqlite3.connect(live) as db:self.assertEqual(before,list(db.iterdump()))
        self.assertFalse((self.root/'application-live.json').exists())

    def test_interrupted_activation_closes_traffic_and_resumes_same_identity(self):
        self.prepare();self.check()
        writer=tool.write_json
        def interrupt(path,value,*args,**kwargs):
            if path.name=='live-activation-journal.json' and value.get('state')=='active':raise RuntimeError('injected interruption')
            return writer(path,value,*args,**kwargs)
        with patch.object(tool,'write_json',interrupt):
            with self.assertRaises(RuntimeError):self.activate()
        result=subprocess.run(['php','-r',"require $argv[1].'/server/application-mode.php';try{echo site_runtime_mode();}catch(Throwable){echo 'CLOSED';}",str(self.root)],capture_output=True,text=True,env=self.env)
        self.assertEqual(result.stdout,'CLOSED')
        self.assertEqual(self.activate()['result'],'LIVE ACTIVATED')
        (self.root/'application-live.json').unlink()
        result=subprocess.run(['php','-r',"require $argv[1].'/server/application-mode.php';try{echo site_runtime_mode();}catch(Throwable){echo 'CLOSED';}",str(self.root)],capture_output=True,text=True,env=self.env)
        self.assertEqual(result.stdout,'CLOSED')


if __name__=='__main__':unittest.main()
