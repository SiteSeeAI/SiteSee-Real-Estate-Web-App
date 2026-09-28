import http.client,importlib.util,json,os,re,shutil,socket,subprocess,tempfile,time,unittest
from pathlib import Path
PROJECT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('SITESEE_TEST_PHP') or shutil.which('php') or '/workspace/scratch/0620b17ff5df/php-bin/php'
class ManagementHTTP(unittest.TestCase):
 def setUp(self):
  self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup);self.root=Path(self.temp.name)
  self.private=self.root/'private';shutil.copytree(PROJECT/'_private',self.private)
  cfg=self.private/'booking-lifecycle.json';cfg.write_text(json.dumps(dict(schema=1,stage='test',enabled=True,recipient='cro@sitesee.ai'),sort_keys=True));cfg.chmod(0o600)
  self.env=os.environ.copy();self.env.update(SITESEE_REAL_ESTATE_SITE_URL='https://re.sitesee.ai',SITESEE_REAL_ESTATE_PRICING_GATE_SECRET='fixture-'*8,SITESEE_REAL_ESTATE_BOOKING_TEST_ENABLED='1',SITESEE_REAL_ESTATE_BOOKING_DB=str(self.private/'data/bookings.sqlite'))
  setup=self.root/'setup.php';setup.write_text('''<?php require $argv[1].'/server/booking-lifecycle.php';
$db=booking_db();booking_communication_schema($db);
$day=(new DateTimeImmutable('+10 days',new DateTimeZone('America/Chicago')))->format('Y-m-d');
$s=real_estate_prepare_submission(['version'=>2,'action'=>'request_appointment','market'=>'residential','details'=>['first'=>'Test','last'=>'Customer','company'=>'Fixture','email'=>'cro@sitesee.ai','phone'=>'5555550100','street'=>'123 Example','unit'=>'','city'=>'Madison','state'=>'WI','zip'=>'53703','optOut'=>'Yes'],'state'=>['category'=>'average','package'=>'custom','sqft'=>'2000','selected'=>['photo'],'videoSeconds'=>60,'images'=>1],'appointment'=>['date'=>$day,'time'=>'09:00','windowMinutes'=>120,'rushRequested'=>false,'meetPhotographer'=>'Yes','cancellationAccepted'=>true]]);
booking_capture($db,$s,'ABC0000001',true);$db->exec("UPDATE bookings SET status='deposit_paid_test',deposit_paid_at='paid',approved_at='reviewed',photographer='David',duration_minutes=95");
$db->prepare("INSERT INTO booking_confirmations(reference,state,calendar_uid,event_uid,planned_start,planned_end,event_json,created_at,confirmed_at,invitation_state) VALUES('ABC0000001','confirmed',?,'saved',?,?,?,? ,?,'sent')")->execute(['microsoft:'.BOOKING_MS_CALENDAR,time()+864000,time()+870000,'{}',gmdate('c'),gmdate('c')]);echo booking_management_issue($db,'ABC0000001');''')
  self.link=subprocess.check_output([PHP,str(setup),str(self.private)],env=self.env,text=True);self.credential=self.link.split('#',1)[1]
  self.router=self.root/'router.php';self.router.write_text('<?php require '+repr(str(self.private/'server/booking-manage.php'))+';')
  sock=socket.socket();sock.bind(('127.0.0.1',0));self.port=sock.getsockname()[1];sock.close()
  self.log=open(self.root/'server.log','w');self.addCleanup(self.log.close)
  self.proc=subprocess.Popen([PHP,'-S','127.0.0.1:'+str(self.port),str(self.router)],env=self.env,stdout=self.log,stderr=self.log)
  self.addCleanup(self.stop);self.cookie=''
  for _ in range(80):
   try:
    s=socket.create_connection(('127.0.0.1',self.port),.1);s.close();break
   except OSError:time.sleep(.025)
 def stop(self):self.proc.terminate();self.proc.wait(timeout=5)
 def request(self,method='GET',data=None,origin='https://re.sitesee.ai'):
  import urllib.parse
  c=http.client.HTTPConnection('127.0.0.1',self.port,timeout=5);body=urllib.parse.urlencode(data or {})
  headers={'Host':'re.sitesee.ai','Cookie':self.cookie,'Origin':origin,'Content-Type':'application/x-www-form-urlencoded'}
  c.request(method,'/manage-appointment.php',body if method=='POST' else None,headers);r=c.getresponse();text=r.read().decode();h=dict(r.getheaders());c.close()
  if h.get('Set-Cookie'):self.cookie=h['Set-Cookie'].split(';',1)[0]
  return r.status,text,h
 def open(self):
  status,html,_=self.request();self.assertEqual(status,200);csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
  status,_,headers=self.request('POST',dict(action='open',csrf=csrf,credential=self.credential));self.assertEqual(status,303)
  self.assertEqual(headers['Location'],'manage-appointment.php');return self.request()
 def test_no_details_before_authentication_and_security_headers(self):
  status,html,h=self.request();self.assertEqual(status,200);self.assertNotIn('123 Example',html);self.assertNotIn('ABC0000001',html)
  self.assertIn('no-store',h['Cache-Control']);self.assertEqual(h['Referrer-Policy'],'no-referrer');self.assertIn("frame-ancestors 'none'",h['Content-Security-Policy'])
  self.assertIn('secure',h['Set-Cookie'].lower());self.assertIn('HttpOnly',h['Set-Cookie']);self.assertIn('SameSite=Strict',h['Set-Cookie'])
 def test_auth_exchange_csrf_and_cross_booking(self):
  status,html,h=self.open();self.assertEqual(status,200);self.assertIn('ABC0000001',html);self.assertNotIn(self.credential,html)
  csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
  self.assertEqual(self.request('POST',dict(action='cancel',reference='ABC0000001',csrf='bad',agreed='yes'))[0],403)
  self.assertEqual(self.request('POST',dict(action='cancel',reference='ABC0000001',csrf=csrf,agreed='yes'),origin='https://evil.example')[0],403)
  self.assertEqual(self.request('POST',dict(action='cancel',reference='ABC0000002',csrf=csrf,agreed='yes'))[0],403)
 def test_invalid_tokens_and_rate_limit(self):
  _,html,_=self.request();csrf=re.search(r'name="csrf" value="([a-f0-9]+)"',html)[1]
  for _ in range(20):self.assertEqual(self.request('POST',dict(action='open',csrf=csrf,credential='ABC0000001.'+'0'*64))[0],403)
  self.assertEqual(self.request('POST',dict(action='open',csrf=csrf,credential=self.credential))[0],429)
 def test_revocation_invalidates_current_session(self):
  self.open()
  import sqlite3
  db=sqlite3.connect(str(self.private/'data/bookings.sqlite'));db.execute("UPDATE booking_lifecycle SET token_hash=?",('0'*64,));db.commit();db.close()
  _,html,_=self.request();self.assertNotIn('ABC0000001',html);self.assertIn('private management link',html)
if __name__=='__main__':unittest.main()
