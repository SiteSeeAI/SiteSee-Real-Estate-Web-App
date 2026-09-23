#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

DOMAIN="re.sitesee.ai"
CPUSER="sitesee"
CORPORATE_CONFIG="/home/sitesee/.sitesee-audit-guard/config.json"
CORPORATE_CONTACT_JS="/home/sitesee/public_html/assets/js/contact.js"
GRAPH_SENDMAIL="/usr/local/bin/sitesee-graph-sendmail"
GRAPH_CONFIG="/home/sitesee/.sitesee-graph-mail.json"
FPM_YAML="/var/cpanel/userdata/${CPUSER}/${DOMAIN}.php-fpm.yaml"
TEST_TO="${3:-sales@sitesee.ai}"

fail(){ echo "STOP: $*" >&2; exit 1; }
note(){ echo; echo "== $* =="; }

[[ ${EUID:-$(id -u)} -eq 0 ]] || fail "Run this installer from WHM > Terminal as root."
[[ $# -ge 2 && $# -le 3 ]] || fail "Usage: bash $0 /absolute/public-root /absolute/private-root [test-recipient]"

PUBLIC_ROOT="$(readlink -f -- "$1")"
PRIVATE_ROOT="$(readlink -f -- "$2")"
[[ -d "$PUBLIC_ROOT" ]] || fail "Public root does not exist: $PUBLIC_ROOT"
[[ -d "$PRIVATE_ROOT" ]] || fail "Private root does not exist: $PRIVATE_ROOT"
[[ "$PUBLIC_ROOT" != "/" && "$PRIVATE_ROOT" != "/" ]] || fail "Refusing a broad filesystem root."
[[ "$PRIVATE_ROOT" != "$PUBLIC_ROOT"/* ]] || fail "The private application must remain outside the public document root."
[[ "$PUBLIC_ROOT" =~ ^/[A-Za-z0-9._/-]+$ && "$PRIVATE_ROOT" =~ ^/[A-Za-z0-9._/-]+$ ]] || fail "Application paths contain unsupported characters."
[[ "$TEST_TO" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[A-Za-z]{2,}$ ]] || fail "The test-recipient address is invalid."
for command in curl openssl python3 uapi; do command -v "$command" >/dev/null || fail "$command is required."; done

PUBLIC_JS="$PUBLIC_ROOT/assets/js/turnstile.js"
CONTACT_ENDPOINT="$PUBLIC_ROOT/contact-submit.php"
PRICING_ENDPOINT="$PUBLIC_ROOT/pricing-request.php"
PRIVATE_CONFIG="$PRIVATE_ROOT/real-estate-form-config.php"
PRIVATE_CONTACT="$PRIVATE_ROOT/server/contact-submit.php"
PRIVATE_PRICING="$PRIVATE_ROOT/server/pricing-request.php"

for path in "$PUBLIC_JS" "$CONTACT_ENDPOINT" "$PRICING_ENDPOINT" "$PRIVATE_CONFIG" "$PRIVATE_CONTACT" "$PRIVATE_PRICING" "$CORPORATE_CONFIG" "$CORPORATE_CONTACT_JS" "$GRAPH_CONFIG"; do
  [[ -f "$path" ]] || fail "Required file is missing: $path"
done
[[ -x "$GRAPH_SENDMAIL" ]] || fail "Corporate Microsoft Graph sendmail transport is missing: $GRAPH_SENDMAIL"

CONFIG_MODE="$(stat -c '%a' "$CORPORATE_CONFIG")"
(( (8#$CONFIG_MODE & 077) == 0 )) || fail "Corporate Turnstile configuration permissions are too broad: $CONFIG_MODE"
GRAPH_CONFIG_MODE="$(stat -c '%a' "$GRAPH_CONFIG")"
(( (8#$GRAPH_CONFIG_MODE & 077) == 0 )) || fail "Corporate Graph configuration permissions are too broad: $GRAPH_CONFIG_MODE"

note "Confirming deployed Real Estate protection markers"
grep -q "re.sitesee.ai" "$PRIVATE_CONFIG" || fail "The private configuration does not target $DOMAIN."
grep -q "/home/sitesee/.sitesee-audit-guard/config.json" "$PRIVATE_CONFIG" || fail "The Corporate SiteSee Audit configuration is not connected."
[[ "$(grep -o '__SITESEE_AUDIT_SITEKEY__' "$PUBLIC_JS" | wc -l)" -eq 1 ]] || fail "The browser asset is already activated or has an unexpected structure."
grep -q "real_estate_contact" "$PRIVATE_CONTACT" || fail "The Contact action is missing."
grep -q "real_estate_pricing" "$PRIVATE_PRICING" || fail "The Pricing Request action is missing."
echo "Deployed source markers: PASS"

note "Detecting PHP and cURL"
PHPVER="$(uapi --output=json --user="$CPUSER" LangPHP php_get_vhost_versions vhost="$DOMAIN" 2>/dev/null |
python3 -c 'import json,sys
d=json.load(sys.stdin); rows=d.get("result",{}).get("data",[])
if isinstance(rows,dict): rows=[rows]
for row in rows:
 value=row.get("version") or row.get("phpversion") or row.get("php_version")
 if value: print(value); break')"
[[ "$PHPVER" =~ ^ea-php[0-9]+$ ]] || fail "Could not identify the active PHP version for $DOMAIN."
PHPBIN="/opt/cpanel/${PHPVER}/root/usr/bin/php"
[[ -x "$PHPBIN" ]] || fail "PHP binary is missing: $PHPBIN"
"$PHPBIN" -r 'exit(version_compare(PHP_VERSION,"8.1.0",">=") && extension_loaded("curl") ? 0 : 1);' || fail "PHP 8.1+ with cURL is required."
echo "PHP/cURL: PASS ($("$PHPBIN" -r 'echo PHP_VERSION;'))"

note "Validating the installed Corporate Turnstile secret"
python3 - "$CORPORATE_CONFIG" <<'PY'
import json, re, sys
from urllib.parse import urlencode
from urllib.request import Request, urlopen
from urllib.error import HTTPError
config=json.load(open(sys.argv[1],encoding='utf-8'))
secret=config.get('secret','')
if not isinstance(secret,str) or not re.fullmatch(r'[A-Za-z0-9_-]{10,200}',secret):
    raise SystemExit('Corporate Turnstile secret is invalid.')
request=Request(
    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    data=urlencode({'secret':secret,'response':'sitesee-real-estate-invalid-preflight'}).encode(),
    headers={'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},
)
try: response=urlopen(request,timeout=20)
except HTTPError as error: response=error
with response:
    if response.code != 200: raise SystemExit('Cloudflare Siteverify did not return HTTP 200.')
    result=json.loads(response.read(16384))
errors=result.get('error-codes',[])
if result.get('success') is not False or 'invalid-input-response' not in errors:
    raise SystemExit('Cloudflare did not return the expected invalid-token response.')
if any(code in errors for code in ['invalid-input-secret','missing-input-secret']):
    raise SystemExit('Cloudflare rejected the installed Corporate secret.')
print('Corporate Turnstile secret and Siteverify: PASS')
PY

note "Staging the installed Corporate public widget key"
BACKUP="$(mktemp -d /root/sitesee-real-estate-forms-backup-XXXXXXXX)"
STAGE="$BACKUP/staged"
mkdir -m 0700 "$STAGE"
cp -a -- "$PUBLIC_JS" "$BACKUP/turnstile.js.original"
if [[ -f "$FPM_YAML" ]]; then
  cp -a -- "$FPM_YAML" "$BACKUP/php-fpm.yaml.original"
  FPM_EXISTED=1
else
  FPM_EXISTED=0
  printf '%s\n' '---' '_is_present: 1' > "$BACKUP/php-fpm.yaml.original"
fi
python3 - "$CORPORATE_CONTACT_JS" "$PUBLIC_JS" "$STAGE/turnstile.js" <<'PY'
import pathlib,re,sys
corporate=pathlib.Path(sys.argv[1]).read_text(encoding='utf-8')
source=pathlib.Path(sys.argv[2]).read_text(encoding='utf-8')
match=re.search(r"sitekey\s*:\s*(['\"])([A-Za-z0-9_-]{10,200})\1",corporate)
if not match: raise SystemExit('Could not read the installed public key from the Corporate contact script.')
sitekey=match.group(2)
if re.match(r'^[123]x0{8,}',sitekey): raise SystemExit('Cloudflare testing keys cannot be installed in production.')
if source.count('__SITESEE_AUDIT_SITEKEY__') != 1: raise SystemExit('Unexpected Real Estate Turnstile asset structure.')
pathlib.Path(sys.argv[3]).write_text(source.replace('__SITESEE_AUDIT_SITEKEY__',sitekey),encoding='utf-8')
print('Existing SiteSee Audit public key staged without displaying it: PASS')
PY

note "Staging the Real Estate PHP-FPM form configuration"
PRICING_SECRET="$(python3 - "$FPM_YAML" <<'PY'
import pathlib,re,secrets,sys
path=pathlib.Path(sys.argv[1])
text=path.read_text(encoding='utf-8') if path.exists() else ''
match=re.search(r"^sitesee_env_SITESEE_REAL_ESTATE_PRICING_GATE_SECRET:\s*\{[^\n]*\bvalue:\s*'([0-9a-f]{64})'",text,re.M)
print(match.group(1) if match else secrets.token_hex(32))
PY
)"
export PRICING_SECRET
python3 - "$BACKUP/php-fpm.yaml.original" "$STAGE/php-fpm.yaml" <<'PY'
import os,pathlib,re,sys
source,target=map(pathlib.Path,sys.argv[1:])
text=source.read_text(encoding='utf-8')
if not text.lstrip().startswith('---'): text='---\n'+text
keys=[
 'sitesee_env_SITESEE_REAL_ESTATE_SITE_URL',
 'sitesee_env_SITESEE_REAL_ESTATE_PRICING_GATE_SECRET',
 'sitesee_env_SITESEE_REAL_ESTATE_SALES_EMAIL',
 'sitesee_env_SITESEE_FROM_EMAIL',
 'sitesee_env_SITESEE_SMTP_HOST',
 'sitesee_env_SITESEE_SMTP_PORT',
 'sitesee_env_SITESEE_SMTP_USERNAME',
 'sitesee_env_SITESEE_SMTP_PASSWORD',
 'sitesee_env_SITESEE_SMTP_ENCRYPTION',
 'sitesee_php_post_max_size',
 'sitesee_php_max_execution_time',
 'sitesee_php_memory_limit',
 'sitesee_php_sendmail_path',
]
pattern=re.compile(r'^(?:'+'|'.join(map(re.escape,keys))+r'):\s*.*(?:\n|$)',re.M)
text=pattern.sub('',text).rstrip()+'\n'
def quote(value): return "'"+str(value).replace("'","''")+"'"
values={
 'sitesee_env_SITESEE_REAL_ESTATE_SITE_URL':('env[SITESEE_REAL_ESTATE_SITE_URL]','https://re.sitesee.ai'),
 'sitesee_env_SITESEE_REAL_ESTATE_PRICING_GATE_SECRET':('env[SITESEE_REAL_ESTATE_PRICING_GATE_SECRET]',os.environ['PRICING_SECRET']),
 'sitesee_env_SITESEE_REAL_ESTATE_SALES_EMAIL':('env[SITESEE_REAL_ESTATE_SALES_EMAIL]','sales@sitesee.ai'),
 'sitesee_env_SITESEE_FROM_EMAIL':('env[SITESEE_FROM_EMAIL]','sales@sitesee.ai'),
 'sitesee_env_SITESEE_SMTP_HOST':('env[SITESEE_SMTP_HOST]',''),
 'sitesee_env_SITESEE_SMTP_PORT':('env[SITESEE_SMTP_PORT]','587'),
 'sitesee_env_SITESEE_SMTP_USERNAME':('env[SITESEE_SMTP_USERNAME]',''),
 'sitesee_env_SITESEE_SMTP_PASSWORD':('env[SITESEE_SMTP_PASSWORD]',''),
 'sitesee_env_SITESEE_SMTP_ENCRYPTION':('env[SITESEE_SMTP_ENCRYPTION]','tls'),
 'sitesee_php_post_max_size':('php_value[post_max_size]','1M'),
 'sitesee_php_max_execution_time':('php_value[max_execution_time]','30'),
 'sitesee_php_memory_limit':('php_value[memory_limit]','128M'),
 'sitesee_php_sendmail_path':('php_value[sendmail_path]','/usr/local/bin/sitesee-graph-sendmail -t -i'),
}
for key,(name,value) in values.items():
    text+=f'{key}: {{ name: {quote(name)}, value: {quote(value)} }}\n'
target.write_text(text,encoding='utf-8')
PY
unset PRICING_SECRET
grep -q "php_value\[sendmail_path\].*/usr/local/bin/sitesee-graph-sendmail -t -i" "$STAGE/php-fpm.yaml" || fail "The staged Graph sendmail path is missing."
echo "PHP-FPM application settings and persistent pricing secret: PASS"

note "Running staged and deployed syntax checks"
for file in "$PRIVATE_CONFIG" "$PRIVATE_CONTACT" "$PRIVATE_PRICING" "$CONTACT_ENDPOINT" "$PRICING_ENDPOINT"; do
  "$PHPBIN" -l "$file" >/dev/null || fail "PHP syntax failed: $file"
done
if command -v node >/dev/null 2>&1; then
  node --check "$STAGE/turnstile.js" || fail "Browser protection syntax failed."
fi
echo "PHP and browser syntax: PASS"

cat > "$BACKUP/rollback.sh" <<ROLLBACK
#!/usr/bin/env bash
set -euo pipefail
cp -a -- '$BACKUP/turnstile.js.original' '$PUBLIC_JS'
if [[ '$FPM_EXISTED' == 1 ]]; then
  cp -a -- '$BACKUP/php-fpm.yaml.original' '$FPM_YAML'
else
  rm -f -- '$FPM_YAML'
fi
/scripts/php_fpm_config --rebuild >/dev/null
/scripts/restartsrv_apache_php_fpm --reload >/dev/null 2>&1 || true
echo 'Previous Real Estate browser asset and PHP-FPM settings restored.'
ROLLBACK
chmod 0700 "$BACKUP/rollback.sh"

ACTIVATED=0
rollback_on_error(){
  status=$?
  trap - ERR
  if (( ACTIVATED )); then
    echo "Installation failed after activation; restoring the previous files and PHP-FPM settings." >&2
    bash "$BACKUP/rollback.sh" >&2 || true
  fi
  exit "$status"
}
trap rollback_on_error ERR

note "Activating the existing SiteSee Audit widget"
ACTIVATED=1
python3 - "$STAGE/turnstile.js" "$PUBLIC_JS" <<'PY'
import os,pathlib,shutil,stat,sys,tempfile
source=pathlib.Path(sys.argv[1]); target=pathlib.Path(sys.argv[2]); current=target.stat()
fd,temp=tempfile.mkstemp(prefix='.turnstile-install-',dir=target.parent)
try:
    with os.fdopen(fd,'wb') as stream:
        stream.write(source.read_bytes());stream.flush();os.fsync(stream.fileno())
    os.chown(temp,current.st_uid,current.st_gid)
    os.chmod(temp,stat.S_IMODE(current.st_mode))
    os.replace(temp,target)
finally:
    if os.path.exists(temp): os.unlink(temp)
PY
/usr/bin/install -o root -g root -m 0600 "$STAGE/php-fpm.yaml" "$FPM_YAML"
/scripts/php_fpm_config --rebuild >/dev/null
/scripts/restartsrv_apache_php_fpm --reload >/dev/null 2>&1 || true

note "Testing live form rejection before any delivery logic"
for endpoint in contact-submit.php pricing-request.php; do
  for mode in missing invalid; do
    args=(-sS -X POST "https://${DOMAIN}/${endpoint}" -H "Origin: https://${DOMAIN}" -H "Sec-Fetch-Site: same-origin" -H "Accept: application/json")
    [[ "$mode" == invalid ]] && args+=(-F "cf-turnstile-response=sitesee-invalid-live-test")
    body="$(curl "${args[@]}" -w $'\n%{http_code}')"
    code="${body##*$'\n'}"; json="${body%$'\n'*}"
    python3 - "$endpoint" "$mode" "$code" "$json" <<'PY'
import json,sys
endpoint,mode,code,raw=sys.argv[1:]
if code != '403': raise SystemExit(f'{endpoint} {mode}-token test returned HTTP {code}, expected 403.')
try: result=json.loads(raw)
except Exception: raise SystemExit(f'{endpoint} {mode}-token test returned unreadable JSON.')
if result.get('ok') is not False or 'secure form check' not in result.get('message','').lower():
    raise SystemExit(f'{endpoint} {mode}-token rejection message was unexpected.')
print(f'{endpoint}: {mode}-token rejection PASS')
PY
  done
done

note "Testing the existing Microsoft Graph mail transport"
printf 'From: SiteSee Real Estate <sales@sitesee.ai>\r\nTo: %s\r\nSubject: SiteSee Real Estate forms Graph transport test\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nSafe to delete. Automated production transport test for the Real Estate Pricing Request and Contact forms.\r\n' "$TEST_TO" | "$GRAPH_SENDMAIL" -t -i
echo "Microsoft Graph transport: PASS"

trap - ERR

echo
echo "===================================================="
echo "REAL ESTATE FORM PROTECTION INSTALLED"
echo "===================================================="
echo "Hostname             : $DOMAIN"
echo "Turnstile widget     : Existing SiteSee Audit managed widget"
echo "Corporate config     : $CORPORATE_CONFIG"
echo "PHP mail transport   : Microsoft Graph sendmail bridge"
echo "Contact action       : real_estate_contact"
echo "Pricing action       : real_estate_pricing"
echo "Transport test       : $TEST_TO"
echo "Backup               : $BACKUP"
echo "Rollback if needed   : bash $BACKUP/rollback.sh"
echo
echo "Final acceptance: in a private browser, submit one Contact inquiry and one Pricing Request."
echo "Confirm the sales notifications, requester confirmations and Pricing approval link."
