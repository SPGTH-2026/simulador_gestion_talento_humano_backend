# Acceso con Google: recorrido de la sesión y validación del `state`

Este documento describe **cómo funciona hoy en SPGTH** el acceso con Google y qué debe cumplir la configuración para que el
callback acepte un intento. No describe un requisito general de OAuth: es consecuencia del diseño de SPGTH, que guarda el `state`
y la sesión autenticada en la **cookie de sesión de Laravel** (no en un token que viaje por la URL).

## Qué valida el callback

`GET /api/auth/google/callback` (`SocialAuthController::callback`) solo canjea el código con Google si el intento **continúa un
inicio hecho desde este mismo navegador**. Antes del canje comprueba el inicio guardado en la sesión:

| Condición | Si no se cumple | Motivo que queda en el registro |
|---|---|---|
| El `state` está en la sesión (lo dejó `/api/auth/google/redirect`) y en la URL del callback | Rechazo | `ausente` |
| Ambos son idénticos (`hash_equals`) | Rechazo, **sin tocar el inicio vigente** | `distinto` |
| El inicio tiene hora registrada | Rechazo | `sin_hora` |
| El inicio tiene menos de 10 minutos | Rechazo | `caducado` |

Un `state` que **coincide** se consume al comprobarlo, acierte o caduque, así que solo sirve una vez. Un `state` que **no
coincide** no invalida el inicio vigente: un callback viejo (otra pestaña, un reintento) no puede romper el intento que sigue en
curso. Un `state` ya usado llega a la sesión sin valor y da `ausente`.

Un rechazo redirige a `/login?error=oauth_estado`, **no canjea el código, no crea cuentas, no vincula Google y no toca la sesión
autenticada**. El registro guarda el motivo, nunca el `state`, el código ni los tokens. No existe un modo alternativo sin `state`.

Con una **sesión ya abierta** (doble clic, «atrás», recargar o un enlace ajeno) el callback tampoco canjea el código ni vincula
nada: si el `state` coincide, lo consume y vuelve al front; si no coincide, solo vuelve al front. Así un callback recibido no puede
cambiar la cuenta de la sesión.

## El recorrido que exige la arquitectura actual

1. **Inicio.** El navegador navega (página completa) a `VITE_GOOGLE_URL`, por defecto la ruta relativa
   `/api/auth/google/redirect` (`Login.jsx`). Laravel guarda en la sesión el `state` y la hora de inicio, y responde con la
   cookie de sesión (sin `SESSION_DOMAIN`, la cookie es **del host** que respondió) y una redirección a Google.
2. **Vuelta.** Google redirige el navegador a `GOOGLE_REDIRECT_URI` con `code` y `state`. Esa petición es una navegación de nivel
   superior con método GET: las cookies `SameSite=Lax` (el valor por defecto en `config/session.php`) **sí** se envían. Con
   `SameSite=Strict` no se enviarían y el intento se rechazaría.
3. **Sesión.** Para que el callback vea el `state`, el host de `GOOGLE_REDIRECT_URI` debe poder recibir la cookie emitida en el
   paso 1. Las cookies no distinguen puertos, pero sí hosts y dominios registrables.
4. **Resultado.** El callback abre la sesión (cookie) y redirige a `FRONTEND_URL`. Para que la SPA use esa sesión, las
   peticiones de la SPA a `/api` deben enviar esa misma cookie.

De 3 y 4 sale la condición práctica: **el host del callback y el host al que la SPA envía sus peticiones deben compartir la
cookie de sesión**. En SPGTH se cumple de dos maneras que aparecen en el repositorio:

| Montaje | Cómo se cumple | Dónde se ve |
|---|---|---|
| Local | Frontend en `localhost:5173` y backend en `localhost:8000` (o `5174` y `8011` en el aislado): mismo host, puertos distintos | `.env.example` (`GOOGLE_REDIRECT_URI=http://localhost:8000/...`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`) |
| Proxy inverso (opción A de `DOCKER.md`) | El callback vuelve por el frontend y el proxy reenvía `/api` al backend; la cookie queda en el origen de la SPA | `DOCKER.md`; `vercel.json` y `netlify.toml` reescriben `/api/*` y `/sanctum/*` hacia el backend |
| Backend publicado aparte (opción B de `DOCKER.md`) | Funciona mientras la cookie del backend y la de la SPA compartan host (puertos distintos) | `DOCKER.md` |

Un diseño con el `state` en una cookie firmada propia, o con intercambio del código desde la API, no tendría esta restricción;
SPGTH no lo usa. Si la cookie no llega, **no se debe relajar la validación**: se corrige el recorrido de la sesión.

## Incidente del 25-nov-2026: la sesión del inicio no se guardaba

**Síntoma.** En producción, con el callback estricto, todo acceso con Google terminaba en `/login?error=oauth_estado` (motivo
`ausente`). El callback antiguo lo ocultaba porque, si no encontraba el `state`, canjeaba el código en modo *stateless*.

**Causa (reproducida con cookies reales en local y en `GoogleSesionNavegadorTest`).** Las rutas de Google viven en
`routes/api.php`, donde `statefulApi()` ya abre la sesión para las peticiones del front, y además llevaban `->middleware('web')`.
El inicio es una navegación desde una página del front (`Referer` del dominio *stateful*) y el navegador ya traía la cookie de
sesión que dejaron `/sanctum/csrf-cookie` y `/api/auth/me`. En esa petición la sesión se iniciaba dos veces:
1. Sanctum descifraba la cookie y leía la fila: el manejador de base de datos quedaba con `exists = true`.
2. El grupo `web` volvía a descifrar la cookie ya descifrada, fallaba y la dejaba en `null`: la sesión arrancaba con un id nuevo.
3. Al guardar, como `exists` seguía en `true`, se hacía `UPDATE … WHERE id = <nuevo>`, que no afecta a ninguna fila. La cookie
   con el id nuevo sí salía al navegador, así que el callback llegaba con una sesión que no existe en la base.

Sin cookie previa (una petición aislada con curl) no fallaba: el id nuevo se insertaba. El mismo defecto afectaba a
`google/pendiente` y `google/pendiente/ficha` (alta nueva con elección de ficha), que también se llaman desde la SPA.

**Corrección.** `sesion.web` (`App\Http\Middleware\SesionWebUnaVez`) usa la sesión que abrió Sanctum cuando la petición viene
del front y, en cualquier otro caso (la vuelta desde Google), corre el grupo `web` completo, CSRF incluido. No cambia la
validación del `state`, la caducidad, el uso único ni la vinculación segura, y no necesita cambios de configuración.

## Qué se pudo contrastar en el repositorio y qué queda pendiente

**Confirmado en el repositorio**
- Rutas `auth/{provider}/redirect|callback` y `google/pendiente[/ficha]` con el middleware `sesion.web` (`SesionWebUnaVez`): sesión por cookie, `SESSION_DRIVER=database` por defecto.
- `Login.jsx` navega a `VITE_GOOGLE_URL ?? '/api/auth/google/redirect'`; `client.js` usa `VITE_API_URL ?? '/api'`.
- `vercel.json` reescribe `/api/:path*` y `/sanctum/:path*` hacia el backend desplegado y el resto a `/index.html`.
- `config/session.php`: `same_site=lax`, `http_only=true`, `lifetime=120`, `domain` y `secure` por variables de entorno.
- `bootstrap/app.php`: `trustProxies` con `TRUSTED_PROXIES` (por defecto solo redes privadas).

**Pendiente de comprobar en producción (no se pudo consultar; nada se cambió)**
- Valor real de `GOOGLE_REDIRECT_URI` y su registro como URI autorizada en Google Cloud Console.
- Valores reales de `VITE_GOOGLE_URL` y `VITE_API_URL` en Vercel (si fueran absolutas hacia otro dominio, la cookie no se compartiría).
- `FRONTEND_URL`, `SESSION_DOMAIN`, `SESSION_SAME_SITE`, `SESSION_SECURE_COOKIE`, `SESSION_LIFETIME` y `SANCTUM_STATEFUL_DOMAINS`.
- `TRUSTED_PROXIES` (detección de HTTPS para la cookie segura).
- Que el acceso con Google ya funcione hoy tal como se describe (comprobación manual, abajo).

## Antes de publicar

1. Confirmar con quien administra Render, Vercel y Google Cloud los valores anteriores.
2. Publicar primero el backend (no hay migraciones). El mensaje `oauth_estado` del frontend puede ir después: mientras no se
   publique, el frontend muestra el aviso genérico de Google.
3. Prueba manual con una **cuenta real de Google** (las pruebas automáticas simulan Google y no la sustituyen):
   - entrar con Google desde el acceso: debe iniciar sesión (o llevar a elegir ficha si la cuenta es nueva);
   - repetir con la pestaña anterior o el botón «atrás»: debe volver al inicio sin error 500;
   - abrir una URL de callback sin haber iniciado el flujo: debe mostrar el aviso `oauth_estado`.
4. Reversión: revertir el commit del backend (no hay migraciones ni datos que deshacer). Volver a `web` a secas reabre el fallo.

## Pruebas

- `tests/Feature/GoogleSesionNavegadorTest.php` con `tests/Support/NavegadorReal.php`: cookies cifradas que viajan entre peticiones,
  driver `database`, `Referer` del front o de Google y estado del servidor reiniciado en cada petición. Cubre cuenta vinculada, alta
  nueva con ficha, callback inválido, reintento, dos pestañas y pestaña antigua.
- `tests/Feature/SocialEstadoOAuthTest.php`: validación del `state`, sesión abierta y flujos válidos.
- `tests/Feature/SocialCallbackSessionTest.php` y `SocialVinculacionSeguraTest.php`: acceso y vinculación, ahora con el inicio real.
- `tests/Support/InicioOAuth.php`: hace el inicio de verdad (controlador y Socialite reales) y **solo simula el canje del código con
  Google**. No prueban consentimiento, tokens ni la verificación de correo de Google: eso es una prueba manual con una cuenta real.
