# UparAula — Backend (Laravel 11 + PHP 8.5)

App de gestión escolar para docentes de Colombia. El frontend vive en otro
repositorio: `FrontUparAula` (React 18 + Vite + TypeScript). La especificación
original está en `PROMPT_UPARAULA_1.md`, en la carpeta padre del proyecto.

El historial detallado del proyecto (fases, bugs reales y sus causas, decisiones
tomadas con el usuario) está en `docs/contexto-claude.md`. Vale la pena leerlo
antes de tocar el motor de notas, la asistencia o los monitores.

## Comandos

```bash
php artisan serve                 # API en http://localhost:8000
php artisan test                  # 116 pruebas (SQLite en memoria, no toca MySQL)
php artisan migrate               # nunca migrate:fresh sin permiso explícito
php artisan queue:work            # obligatorio para que los reportes terminen
```

MySQL local: base `uparaula` en `127.0.0.1:3306`. Se cae seguido; si no responde,
pídele al usuario que la inicie en vez de adivinar comandos.

## Estructura

- **Notas**: `GradeCalculatorService` calcula columnas (`manual`, `from_attendance`,
  `from_participation`, `custom_formula`), definitivas de sección y de período.
  `GradeObserver`/`AttendanceObserver` disparan el recálculo solo.
- **Estructura académica**: `grade_levels` (grados), `shifts` (jornadas),
  `class_blocks` (bloques y descansos por jornada). Los grupos apuntan a un grado
  y una jornada; las materias se vinculan a grados (`grade_level_subject`).
- **Monitores**: `course_monitors`, `monitor_submissions` (propuesta pendiente) y
  `participations`. Nada del monitor toca los datos reales hasta que el docente
  aprueba, y eso pasa por `MonitorReviewService`.
- **Autorización**: `GroupSubjectPolicy` para todo lo del aula, `InstitutionPolicy`
  (`manageAcademics`) para lo institucional. Los monitores quedan fuera de toda la
  API del docente por el middleware `not.monitor`.

## Trampas conocidas (todas costaron un bug real)

- **PHP 8.5**: `public/index.php` y `artisan` llevan `error_reporting(E_ALL & ~E_DEPRECATED)`.
  Sin eso, un aviso de deprecación se imprime antes de los headers y rompe CORS/JSON.
- **Fechas**: nunca compares una columna con cast `date` usando `where()` contra un
  string. Usa `whereDate()`. Tampoco uses `updateOrCreate()` con esas columnas.
- **Relaciones que pisan columnas**: si el modelo tiene columna `x` y relación que
  serializa como `x`, cargarla la reemplaza en el JSON. Pasó con
  `BehaviorAnnotation::registeredBy` y con `Group::gradeLevel` (columna `grade_level`).
  Revisa el nombre serializado antes de hacer `->with()` para una respuesta.
- **Enums**: cambiarlos necesita SQL crudo en MySQL y `->change()` en SQLite
  (no hay `doctrine/dbal`). Mira las migraciones de `document_type` y `column_type`.
- **Fixtures**: crear `SectionFinal`/`PeriodFinal` a mano choca con los observers.
  Deja que la cascada los cree.
- **Validación de archivos**: usa `mimetypes`, no `mimes`, o `UploadedFile::fake()`
  falla siempre.
- `Homework` necesita `$table` explícito: el pluralizador lo trata como incontable.

## Producción

Backend en `https://uparaulaback.upartechnology.com`. Despliegue: subir código,
respaldar la base y `php artisan migrate --force` (nunca `fresh`/`refresh`).
`FRONTEND_URL` del `.env` controla CORS. `SANCTUM_STATEFUL_DOMAINS` no se usa:
la autenticación es por token, `statefulApi()` nunca se registra.
