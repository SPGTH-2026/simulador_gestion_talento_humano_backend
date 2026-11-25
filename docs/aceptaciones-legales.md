# Textos legales y aceptaciones (estructura desactivada)

Estado: **construida y apagada**. Con `LEGAL_ACEPTACIONES_ACTIVAS` sin definir (o distinta de `true`) todas las rutas `api/legal/*`
responden 404 y nada del sistema pide aceptar nada. No hay casillas, ni bloqueo de registro, ni reaceptación; eso es otra decisión.

## Qué existe
- `documentos_legales`: versiones de «privacidad» y «términos». Nacen en **borrador**; el **superadministrador** las aprueba (se
  comprueba en el controlador; no se añadió ningún permiso general). Al aprobar se sella el texto con su huella SHA-256 y la versión
  queda **inmutable** (modelo y API lo impiden; con aceptaciones, la base tampoco deja borrarla).
- `aceptaciones_legales`: una fila por **usuario y versión** (restricción única; repetir la aceptación devuelve la ya hecha).
  Solo el propio usuario autenticado acepta, solo versiones aprobadas. No se guarda IP ni datos del navegador.
- Evidencia: nombre y correo de quien aprobó y de quien aceptó quedan copiados; al **eliminar** una cuenta sus claves pasan a NULL
  (la fila no se borra) y **desactivarla** no toca nada.

## Endpoints (autenticados, detrás de la bandera)
| Método y ruta | Quién |
|---|---|
| `GET legal/documentos`, `POST legal/documentos`, `PUT legal/documentos/{id}` (solo borrador), `POST legal/documentos/{id}/aprobar` | superadministrador |
| `GET legal/documentos/vigentes` (última aprobada por tipo y si ya la aceptaste) | cualquier usuario autenticado |
| `POST legal/documentos/{id}/aceptar` (201 la primera vez, 200 si ya estaba), `GET legal/aceptaciones` (las propias) | el propio usuario |

## Importante
La aprobación es **administrativa**: no equivale a una certificación jurídica del texto. Los textos de las páginas informativas
siguen siendo borradores hasta que se resuelvan las decisiones jurídicas pendientes (responsable, alcance de la autorización
institucional, plazo y procedimiento de conservación).
