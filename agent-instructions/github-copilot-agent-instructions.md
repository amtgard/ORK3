# ORK3 Copilot Instructions

## Project Overview
**ORK3** (Amtgard Online Record Keeper v3) is a PHP/MySQL web application for managing records in the Amtgard LARP community. The architecture follows a three-layered pattern: **orkui** (frontend MVC), **orkservice** (SOAP backend APIs), and **system** (shared business logic).

## Architecture

### Core Components
- **`orkui/`** - Web UI using custom MVC framework. Entry: `orkui/index.php` with routing via GET/POST `Route` parameter (format: `controller/request/action`)
- **`orkservice/`** - SOAP-based microservices. Each service (Kingdom, Player, Award, etc.) has its own directory with patterns: `ServiceName.php` (entry), `ServiceNameService.definitions.php`, `ServiceNameService.function.php`, `ServiceNameService.registration.php`
- **`system/lib/ork3/`** - Shared business logic classes (Authorization, Award, Kingdom, Player, Unit, etc.), auto-loaded in `startup.php`
- **Configuration** - Environment-aware: `config.dev.php` (DEV mode) or `config.php` (prod). Set via `getenv('ENVIRONMENT')`

### Data Flow
1. User requests UI route → `orkui/index.php` parses `Route` parameter
2. Controller instantiated as `Controller_{name}` and calls action method
3. Controller calls business logic via `$this->Service` (e.g., `$this->Login->login()`)
4. Service returns standardized response: `['Status' => int, 'Error' => string, 'Detail' => mixed]`
5. Controller sets `$this->data['key']` for template rendering
6. View renders template from `orkui/template/default/{controller}[_request[_action]].tpl`

## Key Conventions

### Naming & Files
- **Tables**: Prefixed with `ork_` (e.g., `ork_player`, `ork_award`)
- **Controllers**: `controller.{Name}.php` with class `Controller_{Name}`
- **Services**: `{Name}Service.php` with class `{Name}` (e.g., `Player.php` → class `Player`)
- **System classes**: `class.{ClassName}.php` in `system/lib/ork3/`
- **Routes**: `controller/method/action` (e.g., `/orkui/index.php?Route=Player/List/Active`)

### Standard Response Format
All service responses follow this pattern:
```php
Success($detail)     // ['Status' => 0, 'Error' => 'Success', 'Detail' => $detail]
Warning($detail)     // ['Status' => 0, 'Error' => 'Warning', 'Detail' => $detail]
BadToken($detail)    // ['Status' => ServiceErrorIds::SecureTokenFailure, ...]
NoAuthorization($detail)  // ['Status' => ServiceErrorIds::NoAuthorization, ...]
InvalidParameter($detail) // ['Status' => ServiceErrorIds::InvalidParameter, ...]
```
See `orkservice/Common.definitions.php` for error codes.

### MVC Controller Pattern
Controllers extend base `Controller` class with access to:
- `$this->session` - Session management
- `$this->request` - Request parameters
- `$this->data` - Template variables
- `$this->{ServiceName}` - Auto-injected service classes (e.g., `$this->Player`, `$this->Authorization`)
- `$this->template` - Override template path (defaults to `{controller}[_request[_action]].tpl`)

Method signature: `public function method($action = null, $param1 = null, ...)`

### SOAP Service Pattern
Each service file (e.g., `orkservice/Kingdom/KingdomService.php`):
1. Includes `svcutil.php` for SOAP setup
2. Requires `Common.SOAP.php`, `{Service}Service.definitions.php`, `{Service}Service.function.php`, `{Service}Service.registration.php`
3. Instantiates `soap_server` with WSDL namespace
4. Functions use standardized error responses from `Common.definitions.php`

## Global Systems

### Database Access
- Global `$DB` object (YapoMysql class) automatically initialized in `startup.php`
- Usage: `$DB->query($sql)`, `$DB->full_select($sql)`, `$DB->execute($sql)`
- All ORM-like access in service classes via `$this->{ property}` pattern

### Library Container
- `Ork3::$Lib` provides access to auto-loaded system classes: `Ork3::$Lib->player`, `Ork3::$Lib->authorization`, etc.
- Classes auto-discovered from `system/lib/ork3/class.*.php` files

### Sessions & Logging
- `Session` class handles user session state (`$this->session->location`, etc.)
- `Log` class for application logging (auto-initialized, available globally as `$LOG`)
- Trace logging available via `logtrace($label, $data)`

## Development Workflow

### Docker Setup (Recommended)
```bash
docker-compose -f docker-compose.php8.yml up    # Start containers
docker-compose -f docker-compose.php8.yml up -d # Detached
```
Services:
- **PHP/Nginx**: Port 19080 → `http://localhost:19080/orkui/`
- **MariaDB**: Port 24306 (mysql credentials from docker-compose.php8.yml)

### Initial Database Setup
1. Load redacted ORK database (contact technicalad@amtgard.com): `mysql -P 24306 --protocol=tcp -h localhost -u root -proot ork < ~/Downloads/redacted.sql`
2. Fix SQL compatibility: `SET GLOBAL sql_mode = '';`

### Common Commands
- Stop containers: `docker-compose -f docker-compose.php8.yml down`
- Full rebuild: `docker-compose -f docker-compose.php8.yml build --no-cache`
- View logs: `docker-compose -f docker-compose.php8.yml logs -f ork3app`
- Access db in container: `docker exec -it ork3-php8-db mysql -u root -proot ork`

## Important Patterns

### Adding a New Service Function
1. Add function definition in `ServiceNameService.definitions.php`
2. Implement function logic in `ServiceNameService.function.php` (returns standardized response)
3. Register SOAP call in `ServiceNameService.registration.php`
4. Test via `.test.php` file (disabled with `die()` at top when not in use)

### Adding a New UI Controller Action
1. Create `orkui/controller/controller.NewName.php` extending `Controller`
2. Implement action methods: `public function actionName($subaction = null, ...)`
3. Set `$this->data` for template variables
4. Create template: `orkui/template/default/NewName[_actionName[_subaction]].tpl`
5. Link via routes: `UIR.'NewName/method/subaction'`

### Database Migrations
- Place numbered SQL files in `db-migrations/` (e.g., `2021-04-12-add-park-member-since-col`)
- Not auto-applied; run manually via mysql client
- Follow pattern: `ALTER TABLE ork_table_name ...` or `ADD COLUMN ...`

## Technology Stack
- **Backend**: PHP 8.1, SOAP APIs, MariaDB
- **Frontend**: HTML/CSS/JavaScript, custom template engine
- **Infrastructure**: Docker (Nginx + PHP-FPM), MariaDB
- **Languages/i18n**: Multi-language support via `orkui/language/{locale}/` files
- **Assets**: Heraldry images in `assets/heraldry/{player,park,kingdom,event,unit}/`, player images in `assets/players/`

## Debugging Tips
- Set `ENVIRONMENT=DEV` in docker-compose for debug output
- Check PHP logs: `docker-compose logs ork3app | grep PHP`
- Use `logtrace()` for custom trace logging
- SQL Mode must be empty for dev (`SET GLOBAL sql_mode = '';`)
- Memory limit: 512M (configured in Dockerfile)
- Max input vars: 2000 (for large forms)
