import contextlib
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import unittest
import urllib.parse

PROJECT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('SITESEE_TEST_PHP') or shutil.which('php') or '/workspace/scratch/0620b17ff5df/php-bin/php'

class StaffWorkflowHTTP(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  if not Path(PHP).is_file():raise unittest.SkipTest('PHP required')
  cls.temp=tempfile.TemporaryDirectory();cls.root=Path(cls.temp.name)
  shutil.copytree(PROJECT/'_private',cls.root/'_private');(cls.root/'sessions').mkdir()
  cls.env=dict(os.environ,SITESEE_REAL_ESTATE_SITE_URL='https://re.sitesee.ai',SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED='1',
    SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='q'*48,SITESEE_REAL_ESTATE_BOOKING_DB=str(cls.root/'bookings.sqlite'))
  cls.env['SITESEE_REAL_ESTATE_STAFF_PASSWORD_HASH']=subprocess.check_output([PHP,'-r',"echo password_hash('test-password',PASSWORD_DEFAULT);"],env=cls.env).decode()
  seed=cls.root/'seed.php';seed.write_text('''<?php
require __DIR__.'/_private/server/booking-workflow.php';$db=booking_db();booking_communication_schema($db);
$s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential',
'details'=>['first'=>'David','last'=>'Cro','company'=>'SiteSee','email'=>'cro@sitesee.ai','phone'=>'5555550100','street'=>'123 Main','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'],
'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],
'appointment'=>['date'=>(new DateTimeImmutable('+10 days'))->format('Y-m-d'),'time'=>'09:00','windowMinutes'=>120,'rushRequested'=>false,'meetPhotographer'=>'Yes','cancellationAccepted'=>true]]);
booking_capture($db,$s,'CCD0000001',true);$db->exec("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at='paid',approved_at='reviewed',photographer='David',duration_minutes=95");
$c=booking_scheduling_ms_config();file_put_contents(__DIR__.'/_private/microsoft-scheduling.json',json_encode($c));chmod(__DIR__.'/_private/microsoft-scheduling.json',0600);
$db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,event_json,planned_start,planned_end,created_at,confirmed_at) VALUES('CCD0000001','confirmed',?,'immutable-event','{}',?,?,'created','confirmed')")->execute([$c['calendar_uid'],time()+864000,time()+870000]);
''')
  subprocess.run([PHP,str(seed)],env=cls.env,check=True,stdout=subprocess.PIPE)
  router=cls.root/'router.php';router.write_text("<?php require __DIR__.'/_private/server/booking-staff.php';")
  with contextlib.closing(socket.socket()) as sock:sock.bind(('127.0.0.1',0));cls.port=sock.getsockname()[1]
  cls.process=subprocess.Popen([PHP,'-d','session.save_path='+str(cls.root/'sessions'),'-S','127.0.0.1:'+str(cls.port),str(router)],env=cls.env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  for _ in range(60):
   try:
    conn=http.client.HTTPConnection('127.0.0.1',cls.port,timeout=1);conn.request('GET','/staff-bookings.php');conn.getresponse().read();conn.close();break
   except OSError:time.sleep(.03)
  else:raise RuntimeError('PHP server failed to start')
 @classmethod
 def tearDownClass(cls):
  cls.process.terminate();cls.process.wait(timeout=5);cls.temp.cleanup()
 def request(self,data=None,cookie='',origin='https://re.sitesee.ai'):
  conn=http.client.HTTPConnection('127.0.0.1',self.port,timeout=10)
  headers={'Host':'re.sitesee.ai','Cookie':cookie,'Origin':origin,'Content-Type':'application/x-www-form-urlencoded'}
  conn.request('POST' if data is not None else 'GET','/staff-bookings.php?reference=CCD0000001',urllib.parse.urlencode(data) if data is not None else None,headers)
  r=conn.getresponse();body=r.read().decode();result=(r.status,dict(r.getheaders()),body);conn.close();return result
 def login(self):
  status,h,b=self.request();csrf=re.search(r'name="csrf" value="([^"]+)"',b)[1];cookie=h['Set-Cookie'].split(';')[0]
  status,h,b=self.request(dict(action='login',csrf=csrf,password='test-password'),cookie);self.assertEqual(status,303)
  cookie=h['Set-Cookie'].split(';')[0];status,h,b=self.request(cookie=cookie)
  return cookie,re.search(r'name="csrf" value="([^"]+)"',b)[1],b
 def test_auth_csrf_and_origin_guard_new_actions(self):
  status,h,b=self.request();csrf=re.search(r'name="csrf" value="([^"]+)"',b)[1];cookie=h['Set-Cookie'].split(';')[0]
  self.assertNotIn('Assigned calendar',b)
  self.assertEqual(self.request(dict(action='workflow_link',csrf=csrf,reference='CCD0000001'),cookie)[0],403)
  cookie,csrf,b=self.login()
  self.assertEqual(self.request(dict(action='workflow_recover',csrf='bad',reference='CCD0000001'),cookie)[0],403)
  self.assertEqual(self.request(dict(action='workflow_check',csrf=csrf,reference='CCD0000001'),cookie,'https://foreign.example')[0],403)
 def test_inline_crm_prerequisite_and_forged_send_preserve_attempts(self):
  cookie,csrf,b=self.login()
  self.assertIn('Microsoft — sales@re.sitesee.ai / Calendar',b)
  self.assertIn('Not linked',b);self.assertIn('Recover Booking Status',b)
  self.assertNotIn('<button>Send Test Calendar Invitation</button>',b)
  before=sqlite3.connect(str(self.root/'bookings.sqlite'));saved=list(before.execute('SELECT * FROM booking_confirmations'));before.close()
  status,h,b=self.request(dict(action='send_invitation',csrf=csrf,reference='CCD0000001',verify_recipient='yes'),cookie)
  self.assertIn('Link the CRM contact before sending',b)
  status,h,b=self.request(dict(action='workflow_link',csrf=csrf,reference='CCD0000001',contact_id='999',contact_verified='yes'),cookie)
  self.assertIn('selection expired',b)
  db=sqlite3.connect(str(self.root/'bookings.sqlite'));self.assertEqual(list(db.execute('SELECT * FROM booking_confirmations')),saved)
  self.assertEqual(db.execute('SELECT COUNT(*) FROM booking_communications').fetchone()[0],0);db.close()

if __name__=='__main__':unittest.main()
