# 📷 Camera Control Center - Professional Edition

> **Enterprise-grade Raspberry Pi camera control system with real-time streaming, WebSocket support, and advanced monitoring capabilities.**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-777BB4?logo=php)](https://php.net)
[![Security: OWASP](https://img.shields.io/badge/Security-OWASP%20Top%2010-green)](https://owasp.org/www-project-top-ten/)
[![Code Style: PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue)](https://www.php-fig.org/psr/psr-12/)

## 🌟 Features

### Core Functionality
- ✅ **Real-time Camera Control** - Adjust resolution, ISO, saturation, rotation, effects
- ✅ **Live Streaming** - Multiple quality presets (480p to 1024p) with adaptive quality
- ✅ **WebSocket Support** - Real-time updates with sub-second latency
- ✅ **Image Capture** - On-demand high-quality image capture
- ✅ **OCR Integration** - Extract text from images using Google Cloud Vision API
- ✅ **Progressive Web App (PWA)** - Install as mobile/desktop app
- ✅ **Remote Management** - SSH-based reboot, shutdown, and cleanup commands
- ✅ **Comprehensive Logging** - Track all system operations and errors
- ✅ **Tunnel Support** - Multiple tunnel providers (Cloudflare, ngrok, Bore, PageKite)

### Advanced Features
- 🔐 **Security First** - OWASP Top 10 compliant, input validation, XSS protection
- ⚡ **Performance Optimized** - OPcache support, file caching, minimized JavaScript
- 📊 **System Monitoring** - Battery status, network monitoring, resource tracking
- 🔌 **Plugin System** - Extensible architecture for custom features
- 🎨 **Responsive UI** - Material Design-inspired interface, mobile-optimized
- 🌐 **Multi-language Ready** - Support for English, Arabic, and more

## 🏗️ Architecture & Standards

### Code Quality Standards Applied

#### **PHP (PSR-12, OWASP, Clean Code)**
- ✅ Strict type declarations (`declare(strict_types=1)`)
- ✅ PSR-12 coding standards (formatting, naming, structure)
- ✅ SOLID principles (Single Responsibility, Dependency Injection)
- ✅ Comprehensive input validation and sanitization
- ✅ SQL injection prevention (file-based storage, no raw SQL)
- ✅ XSS prevention (`escapeHtml()` for all outputs)
- ✅ Secure session management (HTTPOnly, Secure, SameSite cookies)
- ✅ Thread-safe file operations with atomic writes
- ✅ Comprehensive error logging

#### **JavaScript (Airbnb Style Guide, Google Standards)**
- ✅ Multiple performance modes (jQuery, Vanilla JS, Ultra)
- ✅ Adaptive quality based on network conditions
- ✅ WebSocket with automatic reconnection
- ✅ Page Visibility API for battery optimization
- ✅ Service Worker for offline functionality
- ✅ Debouncing and throttling for performance
- ✅ Modern ES6+ syntax where supported

#### **Bash (Google Shell Style Guide, ShellCheck)**
- ✅ Strict error handling (`set -Eeuo pipefail`)
- ✅ Input validation and sanitization
- ✅ Proper quoting and escaping
- ✅ Comprehensive logging
- ✅ Defensive programming patterns
- ✅ POSIX compliance where possible

#### **Security (OWASP Top 10, NIST)**
- ✅ **A01:2021 - Broken Access Control** - Token-based authentication
- ✅ **A02:2021 - Cryptographic Failures** - Secure credential storage
- ✅ **A03:2021 - Injection** - Input validation, parameterization
- ✅ **A04:2021 - Insecure Design** - Security by design principles
- ✅ **A05:2021 - Security Misconfiguration** - Secure defaults
- ✅ **A06:2021 - Vulnerable Components** - Regular dependency updates
- ✅ **A07:2021 - Auth Failures** - Secure session management
- ✅ **A08:2021 - Data Integrity** - File integrity checks
- ✅ **A09:2021 - Logging Failures** - Comprehensive audit logging
- ✅ **A10:2021 - SSRF** - URL validation and whitelisting

#### **Configuration (Twelve-Factor App)**
- ✅ **I. Codebase** - Single codebase in Git
- ✅ **II. Dependencies** - Explicit dependency declaration
- ✅ **III. Config** - Environment-based configuration (.env)
- ✅ **IV. Backing Services** - Treat services as attached resources
- ✅ **V. Build/Release/Run** - Strict separation of stages
- ✅ **VI. Processes** - Stateless processes
- ✅ **X. Dev/Prod Parity** - Keep environments similar
- ✅ **XI. Logs** - Treat logs as event streams
- ✅ **XII. Admin Processes** - Run admin tasks as one-off processes

## 📋 Requirements

### Server Requirements
- **PHP**: 8.0+ (8.1+ recommended)
- **Extensions**: `json`, `mbstring`, `fileinfo`, `curl`
- **Web Server**: Apache 2.4+ with mod_rewrite OR Nginx 1.18+
- **SSL Certificate**: Required for production (Let's Encrypt recommended)

### Camera (Raspberry Pi) Requirements
- **Hardware**: Raspberry Pi 3B+ or newer
- **OS**: Raspberry Pi OS Lite (64-bit recommended)
- **Camera**: Raspberry Pi Camera Module V2 or compatible
- **Required Packages**:
  - `raspistill` (camera capture)
  - `vcgencmd` (system info)
  - `curl` or `wget` (HTTP client)
  - `jq` (JSON parsing)

### Optional (Enhanced Features)
- **WebSocket Server**: Node.js 16+ for real-time updates
- **OCR**: Google Cloud Vision API key
- **Tunnel**: Cloudflare Tunnel, ngrok, or Bore for remote access

## 🚀 Installation

### Quick Start

```bash
# 1. Clone repository
git clone https://github.com/hmne/Cam1-V1.git
cd Cam1-V1

# 2. Create environment configuration
cp .env.example .env
nano .env  # Edit with your settings

# 3. Set file permissions
chmod 600 .env
chmod 755 script/*_
mkdir -p tmp log
chmod 755 tmp log

# 4. Configure web server
# For Apache: Enable mod_rewrite and set DocumentRoot
# For Nginx: Configure PHP-FPM and location blocks

# 5. Install dependencies (if using Composer)
# composer install --no-dev --optimize-autoloader

# 6. Test installation
php -S localhost:8000  # Development server
# Visit: http://localhost:8000
```

### Detailed Configuration

#### Step 1: Environment Variables

Edit `.env` with your actual values:

```bash
# CRITICAL: Replace placeholders
CAMERA_BASE_URL="https://your-domain.com/cam1-v1"
WEBSOCKET_SERVER_URL="wss://your-vps-ip:8443"
SSH_USERNAME="your_ssh_user"
SSH_PASSWORD="your_secure_password"
```

#### Step 2: Security Hardening

```bash
# Restrict sensitive files
chmod 600 .env config/api-keys.php
chmod 755 admin/*.php
chmod 644 index.php

# Apache: Ensure .htaccess is active
# Nginx: Use equivalent rules in server block
```

#### Step 3: Camera Setup (Raspberry Pi)

```bash
# On Raspberry Pi, copy boot script
scp script/shboot_ pi@raspberrypi:/home/pi/boot.sh

# SSH into Pi and configure
ssh pi@raspberrypi
chmod +x /home/pi/boot.sh

# Edit boot script with your server URL
nano /home/pi/boot.sh
# Replace: readonly BASE_URL="http://XX.com/${DEVICE_ID}"
# With: readonly BASE_URL="https://your-domain.com/${DEVICE_ID}"

# Add to crontab for auto-start
crontab -e
# Add: @reboot /home/pi/boot.sh >> /home/pi/camera.log 2>&1
```

#### Step 4: WebSocket Server (Optional)

```bash
# Install Node.js WebSocket server
cd websocket/
npm install
node server.js &

# Or use PM2 for production
npm install -g pm2
pm2 start server.js --name camera-websocket
pm2 save
```

## 🔧 Configuration

### Performance Tuning

#### PHP (php.ini)
```ini
; Performance
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2

; Security
expose_php=Off
display_errors=Off
log_errors=On

; Limits
memory_limit=256M
upload_max_filesize=80M
post_max_size=80M
max_execution_time=30
```

#### Apache (.htaccess included)
```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /cam1-v1/

    # HTTPS redirect (production)
    # RewriteCond %{HTTPS} off
    # RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</IfModule>
```

#### Nginx (server block)
```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /var/www/Cam1-V1;

    # SSL configuration
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        include fastcgi_params;
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

## 📖 Usage

### Web Interface

1. **Access**: Open browser to `https://your-domain.com/cam1-v1/`
2. **Live View**: Camera stream updates automatically
3. **Controls**: Adjust camera settings in real-time
4. **Capture**: Click camera icon to save high-quality image
5. **OCR**: Upload image to extract text (if configured)

### API Endpoints

#### Get Status
```bash
GET /api/status.php
Response: {"status":"online","uptime":3600,"quality":"medium"}
```

#### Control Camera
```bash
POST /mode.php
Data: action=capture&resolution=3&iso=33333
Response: {"success":true}
```

#### Remote Management
```bash
POST /admin/reboot.php?token=YOUR_TOKEN
Response: {"success":true,"message":"Rebooting camera..."}
```

## 🔒 Security

### Best Practices Implemented

- ✅ **Environment Variables** - Sensitive data in .env (gitignored)
- ✅ **Input Validation** - All inputs sanitized and validated
- ✅ **Output Encoding** - XSS prevention with `escapeHtml()`
- ✅ **CSRF Protection** - Token-based authentication
- ✅ **SQL Injection** - N/A (file-based storage)
- ✅ **Command Injection** - Escaped shell arguments
- ✅ **Path Traversal** - Whitelisted file paths
- ✅ **Rate Limiting** - Prevent brute force attacks
- ✅ **Secure Headers** - X-Frame-Options, CSP, etc.
- ✅ **HTTPS Only** - Secure cookie flag enabled
- ✅ **Logging** - Comprehensive audit trail

### Security Checklist

Before going to production:

- [ ] Enable HTTPS with valid SSL certificate
- [ ] Set `SESSION_COOKIE_SECURE=true` in .env
- [ ] Set `APP_DEBUG=false` in .env
- [ ] Configure firewall (allow 80, 443, 22 only)
- [ ] Set file permissions: `chmod 600 .env`
- [ ] Review and restrict SSH access
- [ ] Enable fail2ban for SSH
- [ ] Configure API key restrictions (Google Cloud)
- [ ] Set up automated backups
- [ ] Test disaster recovery procedures

## 🐛 Troubleshooting

### Common Issues

#### Camera Shows Offline
```bash
# Check camera heartbeat
ls -lh tmp/status.tmp
# Should be updated every 2-3 seconds

# Check camera script
ssh pi@raspberrypi
ps aux | grep boot.sh
tail -f /home/pi/camera.log
```

#### WebSocket Not Connecting
```bash
# Check WebSocket server
netstat -tuln | grep 8443

# Check SSL certificate
openssl s_client -connect YOUR_VPS_IP:8443
```

#### OCR Not Working
```bash
# Verify API key
php -r "require 'config/api-keys.php'; var_dump(OCR_ENABLED);"

# Check API key restrictions in Google Cloud Console
# Ensure domain/IP is whitelisted
```

## 📚 Project Structure

```
Cam1-V1/
├── admin/              # Admin endpoints (reboot, shutdown, cleanup)
│   ├── clean.php
│   ├── clear.php
│   ├── reboot.php
│   └── shutdown.php
├── api/                # API endpoints
│   └── status.php
├── assets/             # Static assets
│   ├── css/            # Stylesheets
│   ├── images/         # Images and icons
│   ├── js/             # JavaScript files
│   └── src/            # TypeScript sources
├── config/             # Configuration files
│   ├── api-keys.php    # API credentials (gitignored)
│   └── app-config.php  # Application configuration
├── includes/           # PHP modules
│   ├── plugins/        # Plugin system
│   ├── SSHHelper.php   # SSH operations
│   └── utilities.php   # Shared utilities
├── log/                # Log files (gitignored)
├── modules/            # Feature modules (OCR, etc.)
│   └── ocr/
├── script/             # Camera scripts (deployed to Pi)
│   ├── shboot_         # Boot script
│   ├── shmain_         # Main loop
│   └── ...
├── tmp/                # Runtime files (gitignored)
├── web/                # Web monitoring interface
├── .env                # Environment config (gitignored)
├── .env.example        # Environment template
├── .gitignore          # Git ignore rules
├── .htaccess           # Apache configuration
├── index.php           # Main entry point
├── manifest.json       # PWA manifest
├── README.md           # This file
└── sw.js               # Service Worker

```

## 🤝 Contributing

Contributions welcome! Please follow these guidelines:

1. **Code Style**: Follow PSR-12 (PHP), Airbnb (JS), Google (Bash)
2. **Security**: Run security audit before submitting
3. **Testing**: Test on Raspberry Pi hardware
4. **Documentation**: Update README and comments
5. **Commits**: Use conventional commit format

### Development Setup

```bash
# Install development dependencies
composer install
npm install

# Run code quality tools
vendor/bin/phpcs --standard=PSR12 .
vendor/bin/phpstan analyze
npm run lint
shellcheck script/*_
```

## 📜 License

MIT License - See [LICENSE](LICENSE) file for details

Copyright (c) 2025 Net Storm

## 🙏 Acknowledgments

### Standards & Best Practices
- [PSR-12](https://www.php-fig.org/psr/psr-12/) - PHP Coding Standards
- [OWASP Top 10](https://owasp.org/www-project-top-ten/) - Security Standards
- [Twelve-Factor App](https://12factor.net/) - Methodology
- [Clean Code](https://www.oreilly.com/library/view/clean-code-a/9780136083238/) - Robert C. Martin
- [Airbnb JavaScript Style Guide](https://github.com/airbnb/javascript)
- [Google Shell Style Guide](https://google.github.io/styleguide/shellguide.html)

### Technologies
- [Raspberry Pi](https://www.raspberrypi.org/) - Hardware platform
- [Google Cloud Vision](https://cloud.google.com/vision) - OCR API
- [WebSocket](https://developer.mozilla.org/en-US/docs/Web/API/WebSocket) - Real-time communication
- [jQuery](https://jquery.com/) - JavaScript library
- [Material Design](https://material.io/design) - UI inspiration

## 📞 Support

- **Issues**: [GitHub Issues](https://github.com/hmne/Cam1-V1/issues)
- **Discussions**: [GitHub Discussions](https://github.com/hmne/Cam1-V1/discussions)
- **Security**: Report vulnerabilities via private disclosure

---

**Built with ❤️ following industry best practices and world-class engineering standards.**
