# Simulador de Procesos de Gestión de Talento Humano — Backend

API REST en Laravel 13 para el simulador de procesos de gestión de talento humano de una
institución educativa.

Esta documentación está escrita para alguien que **nunca ha visto este proyecto**, y que quizá
tampoco haya trabajado antes con Laravel.

---

## Empieza por aquí

Si no sabes por dónde, este es el orden:

| # | Documento | De qué va |
|---|---|---|
| 0 | [Qué es este proyecto](docs/00-que-es-este-proyecto.md) | Qué hace, qué tiene y qué no tiene |
| 1 | [Instalación](docs/01-instalacion.md) | Ponerlo a funcionar desde cero |
| 2 | [Cómo funciona una petición](docs/02-como-funciona-una-peticion.md) | Qué pasa cuando el navegador pide algo |
| 3 | [Estructura del proyecto](docs/03-estructura-del-proyecto.md) | Qué hay en cada carpeta |
| 4 | [Base de datos](docs/04-base-de-datos.md) | Tablas, cuentas de prueba |
| 5 | [Autenticación](docs/05-autenticacion.md) | Login, cookie, sesión |
| 6 | [Códigos OTP](docs/06-codigos-otp.md) | Verificación de correo y recuperación |
| 7 | [Roles y permisos](docs/07-roles-y-permisos.md) | Quién puede hacer qué |
| 8 | [Correo](docs/08-correo.md) | Configurar el envío real |
| 9 | [Login con Google](docs/09-login-con-google.md) | OAuth y sus riesgos |
| 10 | [Configuración](docs/10-configuracion.md) | Todas las variables de entorno |
| 11 | [Pruebas](docs/11-pruebas.md) | Las 28 pruebas y cómo escribirlas |
| 12 | [Convenciones](docs/12-convenciones.md) | Cómo se escribe el código aquí |
| 13 | [Pendientes](docs/13-pendientes.md) | Lo que **no** está terminado |

---

## Arranque rápido

Si solo quieres levantarlo para curiosear:

```bash
# 1. Dependencias
composer install

# 2. Configuración
cp .env.example .env
php artisan key:generate

# 3. Base de datos (crea antes bd_spgth en MySQL)
php artisan migrate --seed

# 4. Arrancar
php artisan serve
```

El backend queda en `http://localhost:8000`.

### Requisitos

- **PHP 8.3** o superior
- **Composer**
- **MySQL** (este proyecto **no** usa SQLite)

### Las dos bases de datos

| Base | Para qué |
|---|---|
| `bd_spgth` | Desarrollo. La puebla `--seed` |
| `bd_spgth_testing` | Pruebas. `phpunit.xml` apunta aquí |

`bd_spgth_testing` **debe existir antes de correr las pruebas**:

```sql
CREATE DATABASE bd_spgth_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Detalle en [Instalación](docs/01-instalacion.md).

### Pruebas

```bash
php artisan test
```

---

## Qué hace este backend

**Ahora mismo, solo autenticación.** Las 10 rutas que expone son de login, registro,
verificación de correo y recuperación de contraseña.

Los roles y permisos ya están definidos (4 roles, 5 subroles, 18 permisos), pero **todavía no
hay rutas de negocio** que los usen. Está anotado en [Pendientes](docs/13-pendientes.md).

## Las 10 rutas

```
POST   /api/auth/register                     registrarse
POST   /api/auth/login                        iniciar sesión
POST   /api/auth/forgot-password              pedir código de recuperación
POST   /api/auth/reset-password               cambiar la contraseña
GET    /api/auth/google/redirect             login con Google (salida)
GET    /api/auth/google/callback              login con Google (vuelta)
GET    /api/auth/me                           quién soy
POST   /api/auth/logout                       cerrar sesión
POST   /api/auth/verification/send            reenviar el código
POST   /api/auth/verification/confirm         confirmar el código
```

Detalle en [Autenticación](docs/05-autenticacion.md).

## Decisiones que conviene conocer

Cuatro cosas que no son obvias, y que sí están documentadas en detalle:

| Decisión | Por qué |
|---|---|
| **Login con cookie, no token** | Sanctum `statefulApi()`. El token ya no viaja ni en JSON ni en la URL |
| **El registro no inicia sesión** | Hay que verificar el correo primero |
| **Google también exige OTP** | Que Google confirme un correo no prueba que esté bien escrito aquí |
| **No se vincula Google por correo** | Evita que alguien tome una cuenta ajena |

## Cuentas de prueba

`php artisan migrate --seed` crea 8 cuentas. La contraseña sale de `SEED_PASSWORD`, y por
defecto es `Dev12345`.

| Correo | Rol | Subrol |
|---|---|---|
| `admin@test.com` | `super_admin` | — |
| `instructor@test.com` | `instructor` | — |
| `general@test.com` | `aprendiz` | `general` |
| `evaluador@test.com` | `aprendiz` | `evaluador` |
| `seleccionador@test.com` | `aprendiz` | `seleccionador` |
| `revisor@test.com` | `aprendiz` | `revisor_documental` |
| `gestor@test.com` | `aprendiz` | `gestor_convocatorias` |
| `aspirante@test.com` | `aspirante` | — |

Todas con `email_verified_at` ya confirmado, para poder probar sin pasar por el correo.

## El frontend

Va en un repositorio aparte: `simulador-gestion-talento-humano-frontend-main`, en la carpeta
hermana. Es un SPA en React + Vite que habla con esta API.

Este repositorio es **solo el backend**. La frontera entre los dos está explicada en
[Estructura del proyecto](docs/03-estructura-del-proyecto.md).

## La pila técnica

| Pieza | Versión |
|---|---|
| PHP | ^8.3 |
| Laravel | ^13.17 |
| MySQL | 8 |
| Sanctum | ^4.0 |
| Socialite | ^5.31 |
| PHPUnit | ^12.5.12 |

## Estructura

```
app/
├── Http/Controllers/Api/     ← AuthController, SocialAuthController
├── Models/                   ← User, EmailOtp, SocialAccount
├── Services/                 ← OtpService
├── Mail/                     ← OtpMail
├── Enums/                    ← Role
├── Providers/                ← AppServiceProvider (permisos, rate limits)
config/                       ← permisos, cors, sanctum
database/
├── migrations/               ← 6 archivos, 11 tablas
└── seeders/                  ← DatabaseSeeder, DemoUsersSeeder
routes/api.php                ← las 10 rutas
tests/Feature/                ← 27 pruebas + 1 unitaria = 28 en total
docs/                         ← esta documentación
```

Detalle completo en [Estructura del proyecto](docs/03-estructura-del-proyecto.md).

---

## Documentación de Laravel

- [Documentación de Laravel 13](https://laravel.com/docs)
- [Laravel Sanctum](https://laravel.com/docs/sanctum)
- [Laravel Socialite](https://laravel.com/docs/socialite)
- [Laravel Pint](https://laravel.com/docs/pint)