# UparAula — Backend (Laravel)

API REST para **UparAula**, desarrollado por UparTechnology. Este directorio (`backUparAula/`) contiene el backend en Laravel 11 + Sanctum + MySQL.

> Estado: **Fase 1 — Esqueleto completo.** Esquema de base de datos completo (30 tablas), autenticación, registro/onboarding de institución, grid de asignación de cursos y `GradeCalculatorService`. Los módulos de planilla, asistencia, comportamiento, citaciones, tareas, bitácora, copias, reportes y PWA offline llegan en fases siguientes.

## Instalación en desarrollo local

```bash
cd backUparAula
composer install
cp .env.example .env
php artisan key:generate
```

Edita `.env`:

```
DB_CONNECTION=mysql
DB_DATABASE=uparaula
DB_USERNAME=root
DB_PASSWORD=
MAIL_FROM_ADDRESS=noreply@uparaula.com
FRONTEND_URL=http://localhost:5173
```

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

El servidor queda disponible en `http://localhost:8000`, con la API bajo `http://localhost:8000/api`.

### Cuenta demo (creada por el seeder)

- **Institución:** I.E. Manuel Germán Cuello Gutiérrez (Valledupar, Cesar)
- **Docente admin:** `hernis@uparaula.com` / `password`
- 2 grupos (10-2MMGC, 11-1), 2 materias (Matemáticas, Trigonometría), 5 estudiantes
- Plantilla de planilla aplicada ("Plantilla Matemáticas 2026") con 4 secciones dinámicas
- Horario semanal y una bitácora de ejemplo

### Tests

```bash
php artisan test
```

Los tests usan SQLite en memoria (configurado en `phpunit.xml`), no tocan la base de datos `uparaula`.

## Notas de compatibilidad

Este proyecto se generó con PHP 8.5. Laravel 11 apunta a PHP 8.2–8.3, así que su config por defecto (`vendor/laravel/framework/config/database.php`) referencia una constante de PDO (`PDO::MYSQL_ATTR_SSL_CA`) marcada como deprecated en 8.5. Por eso `public/index.php` y `artisan` silencian `E_DEPRECATED` explícitamente — sin ese ajuste, el aviso se imprime como HTML antes de los headers HTTP y corrompe las respuestas JSON (incluyendo los headers CORS). Si en el futuro actualizas a una versión de Laravel/PHP donde esto ya no ocurra, puedes quitar esas dos líneas.

## Despliegue en producción (VPS Linux)

```bash
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan migrate --force
chown -R www-data:www-data storage bootstrap/cache
```

Configura Nginx + Supervisor (`queue:work`) + Cron (`schedule:run`).

## Stack

Laravel 11 (PHP 8.3+) · MySQL 8 · Sanctum · DomPDF · PhpSpreadsheet
