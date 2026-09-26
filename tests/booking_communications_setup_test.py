import importlib.util
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

TOOLS=Path(__file__).resolve().parents[1]/'tools'
def module(name):
    spec=importlib.util.spec_from_file_location(name,TOOLS/(name+'.py'));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
setup=module('setup-booking-communications');installer=module('install-booking-communications')

class CommunicationSetupTests(unittest.TestCase):
    def test_secret_save_is_private_and_no_clobber(self):
        with tempfile.TemporaryDirectory() as d,patch.object(setup,'ROOT',Path(d)):
            p=Path(d)/'config.json';a=SimpleNamespace(pw_uid=os.getuid(),pw_gid=os.getgid())
            setup.save_new(p,{'refresh_token':'fixture'},a)
            self.assertEqual(p.stat().st_mode&0o777,0o600)
            with self.assertRaises(setup.SetupError):setup.save_new(p,{'refresh_token':'replacement'},a)
            self.assertEqual(json.loads(p.read_text())['refresh_token'],'fixture')
    def test_unsafe_permissions_and_symlinks_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'secret.json';p.write_text('{}');p.chmod(0o644)
            with self.assertRaises(setup.SetupError):setup.load_private(p,os.getuid())
            p.chmod(0o600);s=Path(d)/'link.json';s.symlink_to(p)
            with self.assertRaises(setup.SetupError):setup.load_private(s,os.getuid())
    def test_package_mismatch_cannot_replace_server(self):
        with tempfile.TemporaryDirectory() as d:
            base=Path(d);package=base/'package';root=base/'root';(package/'payload').mkdir(parents=True);root.mkdir()
            (package/'payload'/'example.py').write_text('x=1\n');(root/'example.py').write_text('x=2\n')
            manifest={'files':{'example.py':{'sha256':installer.digest(package/'payload'/'example.py'),'previous_sha256':'wrong'}},'preserve':{}}
            with self.assertRaises(RuntimeError):installer.preflight(package,root,manifest,'unused')
            self.assertEqual((root/'example.py').read_text(),'x=2\n')
    def test_install_and_repeat_preserve_credentials(self):
        with tempfile.TemporaryDirectory() as d:
            base=Path(d);package=base/'package';root=base/'root';(package/'payload').mkdir(parents=True);root.mkdir()
            (package/'payload'/'example.py').write_text('x=1\n');(root/'example.py').write_text('x=2\n');(root/'zoho-calendar.json').write_text('private-calendar')
            manifest={'files':{'example.py':{'sha256':installer.digest(package/'payload'/'example.py'),'previous_sha256':installer.digest(root/'example.py')}},'preserve':{}}
            backup=installer.install(package,root,manifest,'unused',os.getuid(),os.getgid())
            self.assertEqual((backup/'example.py').read_text(),'x=2\n')
            installer.install(package,root,manifest,'unused',os.getuid(),os.getgid())
            self.assertEqual((root/'zoho-calendar.json').read_text(),'private-calendar')
    def test_path_escape_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            with self.assertRaises(RuntimeError):installer.checked_path(Path(d),'../outside')
    def test_scope_separation(self):
        self.assertNotIn('ZohoCalendar',setup.SCOPES)
        self.assertIn('ZohoCRM.org.READ',setup.SCOPES)
        self.assertIn('ZohoCRM.modules.emails.ALL',setup.SCOPES)

if __name__=='__main__':unittest.main()
