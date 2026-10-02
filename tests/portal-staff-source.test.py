"""The staff presentation update must not alter authentication or action code."""
import base64, json, pathlib, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
DATA=json.loads((ROOT/'documents/portal/staff-review-source.json').read_text())
class PreservedControls(unittest.TestCase):
    def versions(self,name):
        return base64.b64decode(DATA['files']['private/server/'+name]['before']).decode(),(ROOT/'_private/server'/name).read_text()
    def test_authentication_and_every_post_handler_are_byte_identical(self):
        before,after=self.versions('booking-staff.php')
        start='$hash = booking_staff_password_hash();';end='$csrf = staff_escape(staff_csrf());'
        self.assertEqual(before[before.index(start):before.index(end)],after[after.index(start):after.index(end)])
        start='function staff_csrf()';end='function staff_money('
        self.assertEqual(before[before.index(start):before.index(end)],after[after.index(start):after.index(end)])
    def test_workflow_logic_and_form_builder_are_byte_identical(self):
        before,after=self.versions('booking-workflow.php');end='function booking_workflow_html('
        self.assertEqual(before[:before.index(end)],after[:after.index(end)])
if __name__=='__main__':unittest.main()
