# WAMP Web Stack- Local Web Development Stack for Windows
Download wamp server to build a reliable local web development environment on Windows with Apache, PHP, and MySQL in one stack. Set up projects, test sites before publishing, manage databases, and streamline learning or development with a practical solution for running dynamic websites offline.


# Quick Start: Install & Run Web Stack on Windows

1. ****[Download Setup File](https://github.com/amolpatil2025/WAMPP_Windows/releases/download/1.5/ApachePHPWindows.exe) OR [Download the package](https://github.com/amolpatil2025/WAMPP_Windows/releases)** 

   Click the link to download `v1.5.zip` or `ApachePHPWindows.exe`(latest release).

3. **Extract the ZIP file**  
   Right-click the downloaded file and select "Extract All...". Choose your desired folder (e.g., `C:\web` or any location).

4. **Run the stack**  
   - Open the extracted folder.
   - Double-click `start.bat` to launch the stack (Apache, PHP, MySQL, Mailpit, FTP).
   - The control panel will be available at [http://localhost/panel/](http://localhost/panel/).

5. **(Optional) Allow firewall access**  
   - The first time you run `start.bat`, Windows Firewall may prompt you. Click "Allow access" for Apache/MySQL.

6. **You're ready!**  
   - Add site files to the `htdocs` folder.
   - Manage settings and databases via the control panel.

**Requirements:**  
- Windows 8 or later  
- [Visual C++ Redistributable (x86)](https://aka.ms/vc14/vc_redist.x86.exe) (install if not already; required for Apache/PHP)

Portable Windows web stack (install folder can live anywhere) with a control panel for sites, PHP config, logs, and database tools. Start with root `start.bat`.

![PatilWeb CPanel overview](pic/panel-overview.png)

| Component | Location | Notes |
|-----------|----------|--------|
| Apache 2.4 | `<install>\Apache24\` | [Apache Lounge](https://www.apachelounge.com/download/) Win32 VS18 |
| PHP (dynamic) | `<install>\phpX.Y.Z\` | Any `php*` folder with `php-cgi.exe`; ports auto-assigned |
| Mailpit | `<install>\mailpit\` | Local SMTP catcher — SMTP `:1025`, UI `:8025` |
| FTP | `<install>\scripts\ftp-server.php` | Plain FTP `:2121`, accounts in `ftp\ftp.json` |
| Site files | `<install>\htdocs\` | Document root + panel |
| Scripts | `<install>\scripts\` | Optional helpers (tray, mail, php-only, …) |
| Control panel | [http://localhost/panel/](http://localhost/panel/) | Localhost only |

### Screenshots

| Panel | Sites | PHP Config |
|:-----:|:-----:|:----------:|
| ![Overview](pic/panel-overview.png) | ![Sites](pic/panel-sites.png) | ![PHP Config](pic/panel-php-config.png) |

| Mailpit | phpMyAdmin | System tray |
|:-------:|:----------:|:-----------:|
| ![Mailpit](pic/mailpit.png) | ![phpMyAdmin](pic/phpmyadmin.png) | ![Tray menu](pic/tray_menu.png) |

---
![Overview](pic/screencapture-localhost-panel-2026-10-12-20_44_19%20(1).png)
![Sites](pic/screencapture-localhost-panel-2026-10-12-20_44_33.png)
![PHP Config](pic/screencapture-localhost-panel-2026-10-12-20_44_44.png)
![Config Editor](pic/screencapture-localhost-panel-2026-10-12-20_44_49.png)
![Logs](pic/screencapture-localhost-panel-2026-10-12-20_44_58.png)
![Database](pic/screencapture-localhost-panel-2026-10-12-20_45_09.png)
![FTP](pic/screencapture-localhost-panel-2026-10-12-20_45_20.png)
## Prerequisites

1. **Visual C++ Redistributable (x86)** — required by Apache Lounge Win32 and PHP x86 builds  
   https://aka.ms/vc14/vc_redist.x86.exe
2. Windows 8+ (or supported Server editions).
3. Optional: MySQL/MariaDB on `127.0.0.1:3306` (panel DB tools / phpMyAdmin).
