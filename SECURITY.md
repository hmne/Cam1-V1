# 🔐 Security Policy

## Our Commitment to Security

Security is our top priority. This project follows industry-leading security standards and best practices to protect your camera system, data, and privacy.

---

## 🛡️ Security Standards Compliance

### Applied Standards

- ✅ **[OWASP Top 10 (2021)](https://owasp.org/www-project-top-ten/)** - Web application security risks
- ✅ **[OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)** - Application Security Verification Standard
- ✅ **[CWE Top 25](https://cwe.mitre.org/top25/)** - Most dangerous software weaknesses
- ✅ **[NIST Cybersecurity Framework](https://www.nist.gov/cyberframework)** - Security and privacy controls
- ✅ **[PSR-12](https://www.php-fig.org/psr/psr-12/)** - PHP coding standards (security-related)
- ✅ **[GDPR](https://gdpr.eu/)** - Data protection (where applicable)

---

## 🎯 OWASP Top 10 2021 - Our Protection Measures

### A01:2021 - Broken Access Control ✅

**Protections Implemented:**
- Token-based authentication for admin endpoints
- Session management with `admin_token` validation
- IP logging for unauthorized access attempts
- File path whitelisting (`ALLOWED_WRITABLE_FILES`)
- Directory traversal prevention

**Code Reference:**
```php
// includes/utilities.php:382
function validateAdminToken(): void {
    if (!isset($_GET['token']) || $_GET['token'] !== $_SESSION['admin_token']) {
        http_response_code(403);
        logMessage("Unauthorized access from IP: " . $_SERVER['REMOTE_ADDR']);
        exit;
    }
}
```

---

### A02:2021 - Cryptographic Failures ✅

**Protections Implemented:**
- Sensitive data in `.env` file (gitignored)
- API keys in separate `config/api-keys.php` (gitignored)
- Secure session cookies (HTTPOnly, Secure, SameSite)
- TLS/HTTPS enforcement in production
- Credentials never hardcoded in source code

**Configuration:**
```php
// config/app-config.php:42-45
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.cookie_secure', '1'); // HTTPS only
```

---

### A03:2021 - Injection ✅

**Protections Implemented:**
- **SQL Injection**: N/A (file-based storage, no database)
- **Command Injection**: All shell arguments properly escaped
- **Path Injection**: File path whitelist validation
- **LDAP Injection**: N/A (not using LDAP)
- Input validation and sanitization for all user inputs

**Code Reference:**
```php
// includes/utilities.php:235
function sanitizeInteger($value, $minValue, $maxValue, $defaultValue): int {
    if (!is_numeric($value)) return $defaultValue;
    $intValue = (int)$value;
    if ($intValue < $minValue || $intValue > $maxValue) {
        logMessage("Integer out of range: $intValue");
        return $defaultValue;
    }
    return $intValue;
}
```

**Shell Script Security:**
```bash
# script/shmain_:23-26
[[ "$DEVICE_ID" =~ ^[a-zA-Z0-9_-]+$ ]] || {
    printf '[CRITICAL] Invalid DEVICE_ID format\n' >&2
    reboot -f
}
```

---

### A04:2021 - Insecure Design ✅

**Protections Implemented:**
- Security by Design principles
- Threat modeling for camera control system
- Separation of concerns (config, business logic, presentation)
- Fail-safe defaults (restrictive permissions)
- Rate limiting for OCR and admin operations

**Architecture:**
```
[Camera/Pi] → [HTTPS/TLS] → [Web Server] → [PHP App] → [File Storage]
     ↓                                           ↓
[Local Storage]                         [Admin Controls]
                                               ↓
                                        [Token Auth Required]
```

---

### A05:2021 - Security Misconfiguration ✅

**Protections Implemented:**
- Error messages don't expose sensitive information
- `display_errors=Off` in production
- Unnecessary HTTP headers removed
- Security headers enabled (X-Frame-Options, CSP, etc.)
- Default credentials changed (must configure .env)
- Unnecessary features disabled by default

**Security Headers:**
```php
// includes/utilities.php:460-466
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: ...');
header('Strict-Transport-Security: max-age=31536000');
```

---

### A06:2021 - Vulnerable and Outdated Components ✅

**Protections Implemented:**
- Minimal dependencies (reduces attack surface)
- Regular dependency updates
- PHP 8.0+ required (modern, secure version)
- jQuery 3.7.1 (latest stable)
- No known vulnerable packages

**Dependency Management:**
```json
// Regularly check for updates
composer outdated
npm outdated
```

---

### A07:2021 - Identification and Authentication Failures ✅

**Protections Implemented:**
- Strong session management
- Session ID regeneration every 15 minutes
- Session timeout (configurable, default 1 hour)
- CSRF token generation and validation
- SSH key authentication support (preferred over passwords)
- No default/weak credentials

**Session Security:**
```php
// bootstrap.php:158-172
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');

// Regenerate session ID periodically
if (time() - $_SESSION['last_regeneration'] > 900) {
    session_regenerate_id(true);
}
```

---

### A08:2021 - Software and Data Integrity Failures ✅

**Protections Implemented:**
- Atomic file writes (temp file + rename)
- File locking for concurrent access
- Checksum validation for uploads
- No unsigned/unverified code execution
- File size limits enforced

**Atomic Write Implementation:**
```php
// includes/utilities.php:163-194
function writeFileAtomic(string $filePath, string $data): bool {
    $tempFile = $filePath . '.tmp.' . getmypid() . '.' . mt_rand();
    // ... write to temp, lock, rename atomically
}
```

---

### A09:2021 - Security Logging and Monitoring Failures ✅

**Protections Implemented:**
- Comprehensive logging for all operations
- Failed authentication attempts logged
- File operation errors logged
- IP address logging for security events
- Log files protected (not web-accessible)
- Timestamps for all log entries

**Logging:**
```php
// includes/utilities.php:62
function logMessage(string $message, string $level = 'ERROR'): void {
    $timestamp = date('d/m/Y h:i:s A');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $logEntry = "[$timestamp] [$level] [IP:$ip] $message\n";
    // ... thread-safe logging
}
```

---

### A10:2021 - Server-Side Request Forgery (SSRF) ✅

**Protections Implemented:**
- URL validation with whitelist
- No user-controlled URLs to internal resources
- Timeout limits on external requests
- Validation of URL schemes (HTTP/HTTPS only)

**URL Validation:**
```php
// includes/utilities.php:279-296
function validateUrl(string $url) {
    $sanitizedUrl = filter_var($url, FILTER_VALIDATE_URL);
    $scheme = parse_url($sanitizedUrl, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }
    return $sanitizedUrl;
}
```

---

## 🔒 Additional Security Measures

### File Upload Security

```php
// config/app-config.php:139-150
define('MAX_READABLE_FILE_SIZE', 1048576);      // 1 MB
define('MAX_UPLOADABLE_FILE_SIZE', 80000000);   // 80 MB
define('ALLOWED_FILE_EXTENSIONS', ['jpg', 'jpeg', 'tmp', 'txt', 'log']);
define('ALLOWED_UPLOAD_DIRECTORIES', ['tmp', 'log', '']);
```

### Content Security Policy (CSP)

```
default-src 'self';
script-src 'self' 'unsafe-inline' 'unsafe-eval';
style-src 'self' 'unsafe-inline';
img-src 'self' data: blob:;
connect-src 'self' wss: ws:;
```

### Rate Limiting

```php
// config/api-keys.php:131
define('OCR_RATE_LIMIT', 60); // 60 requests/hour per IP
```

---

## 🚨 Reporting a Vulnerability

### Responsible Disclosure

We take security vulnerabilities seriously. If you discover a security issue, please follow responsible disclosure practices:

### How to Report

1. **DO NOT** create a public GitHub issue
2. **DO NOT** disclose the vulnerability publicly
3. **Send a private disclosure** to: security@example.com (replace with your email)

### What to Include

- Description of the vulnerability
- Steps to reproduce
- Proof of concept (if applicable)
- Potential impact assessment
- Suggested fix (optional)

### Response Timeline

- **24 hours**: Acknowledgment of your report
- **7 days**: Initial assessment and severity classification
- **30 days**: Fix deployed (for critical vulnerabilities)
- **90 days**: Public disclosure (coordinated with reporter)

### Bug Bounty

Currently, this is an open-source project without a formal bug bounty program. However, we will:
- Acknowledge your contribution in our security hall of fame
- Credit you in release notes (if you wish)
- Provide a detailed write-up of the fix

---

## ✅ Security Checklist for Deployment

### Before Going to Production

#### Configuration
- [ ] Copy `.env.example` to `.env`
- [ ] Set `APP_ENV=production`
- [ ] Set `APP_DEBUG=false`
- [ ] Configure `CAMERA_BASE_URL` with HTTPS
- [ ] Set strong `SSH_PASSWORD` or use SSH keys
- [ ] Configure `GOOGLE_VISION_API_KEY` (if using OCR)
- [ ] Set `SESSION_COOKIE_SECURE=true`

#### File Permissions
```bash
chmod 600 .env
chmod 600 config/api-keys.php
chmod 755 admin/*.php
chmod 644 index.php *.php
chmod 755 script/*_
chmod 755 tmp/ log/
```

#### Web Server
- [ ] Enable HTTPS with valid SSL certificate
- [ ] Enable HTTP → HTTPS redirect
- [ ] Disable directory listing
- [ ] Set appropriate PHP limits
- [ ] Enable OPcache for performance
- [ ] Configure fail2ban for SSH

#### Firewall
```bash
# Allow only necessary ports
ufw allow 22/tcp   # SSH
ufw allow 80/tcp   # HTTP (redirects to HTTPS)
ufw allow 443/tcp  # HTTPS
ufw enable
```

#### SSH Hardening
```bash
# /etc/ssh/sshd_config
PermitRootLogin no
PasswordAuthentication no  # Use keys only
PubkeyAuthentication yes
MaxAuthTries 3
```

#### Database (Not Applicable)
- This project uses file-based storage
- No database hardening needed

#### Monitoring
- [ ] Set up log rotation
- [ ] Configure monitoring/alerting
- [ ] Enable security event logging
- [ ] Set up automated backups

---

## 🔐 Secrets Management

### Environment Variables (.env)

**NEVER** commit `.env` to version control:

```bash
# Verify .env is gitignored
git status | grep .env  # Should show nothing

# If .env is tracked, remove it:
git rm --cached .env
git commit -m "Remove .env from version control"
```

### API Keys (config/api-keys.php)

**NEVER** commit `config/api-keys.php`:

```bash
# Add to .gitignore (already included)
config/api-keys.php
```

### SSH Credentials

**BEST PRACTICE**: Use SSH key authentication instead of passwords

```bash
# Generate SSH key
ssh-keygen -t ed25519 -C "camera-control"

# Copy to Raspberry Pi
ssh-copy-id pi@raspberrypi

# In .env, leave password empty
SSH_USERNAME=pi
SSH_PASSWORD=""
```

---

## 🛠️ Security Testing

### Automated Security Scanning

```bash
# PHP Security Check
composer require sensiolabs/security-checker --dev
./vendor/bin/security-checker security:check

# Static Analysis
composer require phpstan/phpstan --dev
./vendor/bin/phpstan analyse

# Shell Script Security
shellcheck script/*_

# Dependency Vulnerabilities
npm audit
composer audit
```

### Manual Security Testing

```bash
# Test XSS Protection
curl -X POST http://localhost/mode.php \
     -d 'action=<script>alert(1)</script>'
# Should be escaped in logs

# Test SQL Injection (N/A - no database)
# This project uses file-based storage

# Test Authentication
curl http://localhost/admin/reboot.php
# Should return 403 Forbidden

# Test HTTPS Redirect
curl -I http://your-domain.com
# Should return 301 → https://

# Test Security Headers
curl -I https://your-domain.com
# Check for X-Frame-Options, CSP, etc.
```

---

## 📚 Security Resources

### Official Documentation
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/)
- [OWASP ASVS](https://owasp.org/www-project-application-security-verification-standard/)
- [CWE Top 25](https://cwe.mitre.org/top25/)

### PHP Security
- [PHP The Right Way - Security](https://phptherightway.com/#security)
- [OWASP PHP Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/PHP_Configuration_Cheat_Sheet.html)
- [Secure PHP Development](https://www.php.net/manual/en/security.php)

### Web Security
- [Mozilla Web Security Guidelines](https://infosec.mozilla.org/guidelines/web_security)
- [Google Web Security Best Practices](https://developers.google.com/web/fundamentals/security)
- [Let's Encrypt Documentation](https://letsencrypt.org/docs/)

### Tools
- [OWASP ZAP](https://www.zaproxy.org/) - Web Application Security Scanner
- [Nikto](https://cirt.net/Nikto2) - Web Server Scanner
- [Snyk](https://snyk.io/) - Dependency Security Scanner
- [SonarQube](https://www.sonarqube.org/) - Code Quality & Security

---

## 📜 Security Updates

### Versioning

We follow [Semantic Versioning](https://semver.org/) for security updates:

- **MAJOR.x.x** - Breaking changes (may include security improvements)
- **x.MINOR.x** - New features (backward compatible)
- **x.x.PATCH** - Bug fixes and security patches

### Security Advisories

Check for security advisories at:
- [GitHub Security Advisories](https://github.com/hmne/Cam1-V1/security/advisories)
- [Release Notes](https://github.com/hmne/Cam1-V1/releases)

### Update Notifications

Star and watch this repository to receive security notifications:
- Navigate to: https://github.com/hmne/Cam1-V1
- Click: **Watch** → **Custom** → **Security alerts**

---

## 🙏 Acknowledgments

We thank the security community for their contributions:

### Security Researchers
- (Your name could be here - report vulnerabilities responsibly!)

### Security Standards Organizations
- OWASP Foundation
- NIST
- CWE/SANS Institute
- PHP Security Consortium

---

## 📞 Contact

- **Security Issues**: security@example.com (replace with your email)
- **General Support**: https://github.com/hmne/Cam1-V1/issues
- **Documentation**: https://github.com/hmne/Cam1-V1/wiki

---

**Last Updated**: 2025-11-24
**Security Policy Version**: 1.0.0
**Next Review**: 2026-02-24 (Quarterly)
