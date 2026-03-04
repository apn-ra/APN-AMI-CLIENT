# Real PBX (Asterisk AMI) Setup

## 1) Configure AMI credentials
Set these environment variables before running real PBX validation:

```bash
export AMI_HOST=127.0.0.1
export AMI_PORT=5038
export AMI_USERNAME=amiuser
export AMI_SECRET=change-me
export AMI_TLS=false
export AMI_SERVER_KEY=pbx01
export AMI_CONNECT_TIMEOUT_MS=2000
export AMI_AUTH_TIMEOUT_MS=2000
```

You can also keep local values in `tests/.secrets/ami.local.php`. This path is gitignored.

## 2) Asterisk requirements (`manager.conf`)
Create an AMI user in `/etc/asterisk/manager.conf` with read permissions needed for PJSIP actions:

```ini
[amiuser]
secret = your-strong-secret
deny=0.0.0.0/0.0.0.0
permit=YOUR_CLIENT_IP/255.255.255.255
read = system,call,log,verbose,command,agent,user,config,dtmf,reporting,cdr,dialplan,originate
write = command,originate
writetimeout = 5000
```

Then reload manager config from Asterisk CLI:

```bash
asterisk -rx "manager reload"
```

## 3) Run locally
Run the real PBX action validation pipeline from this repository root after credentials are set:

```bash
composer run realpbx:test
```

Reports are written to `docs/real-pbx-runs/<datetime>_*.md` with redaction applied to sensitive values.
