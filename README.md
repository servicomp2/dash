# 🚀 DASH-API v1 (PHP 8.4+)

Bienvenido a la documentación oficial de la API de DASH API. Esta es una API REST dinámica, profesional y altamente segura, protegida por **JWT (JSON Web Tokens)**, con auditoría en tiempo real y 100% compatible con **PHP 8.4**.

---

## 🛠️ Requisitos e Instalación

Para asegurar el funcionamiento de todas las características avanzadas (como alertas por email), sigue estos pasos:

1.  **Dependencias**: Ejecuta `composer install` en la carpeta raíz de la API para instalar **PHPMailer**.
2.  **Configuración**: Crea o edita el archivo [`.env`](/www/html/api/.env) con tus credenciales de base de Datos y SMTP.
3.  **Base de Datos**: La API generará automáticamente las tablas `login_attempts` y `api_logs` la primera vez que se ejecute.

---

## 🔒 Seguridad y Autenticación

La API utiliza un esquema de seguridad de triple capa:

1.  **Throttling (Bloqueo de IP)**: Si un usuario falla **3 intentos** de login, su IP será bloqueada por **15 minutos**.
2.  **Base de Datos**: Verifica las credenciales (`email` y `password`) contra la tabla `users`.
3.  **JWT (Stateless)**: Una vez logueado, se emite un token firmado. **El token es obligatorio para todas las peticiones de datos.**

### 🔑 Paso 1: Obtener tu Token (Login)

Petición POST al endpoint `/login`:
**URL:** `http://tudominio/api/api.php/login`

**Cuerpo (JSON):**
```json
{
  "email": "tu-usuario@correo.com",
  "password": "tu-password-real"
}
```

### 📡 Paso 2: Autenticación Bearer

Para cualquier consulta protegida, debes enviar el token en el encabezado estándar **`Authorization`**:

```http
Authorization: Bearer <TU_TOKEN_AQUÍ>
```

---

## 🛡️ Características Avanzadas (DASH Core)

### 🗑️ Soft Deletes (Borrado Lógico)
La API detecta automáticamente columnas llamadas `deleted_at`.
-   **Lectura**: Las consultas `GET` filtran automáticamente registros borrados (`deleted_at IS NULL`).
-   **Borrado**: Las peticiones `DELETE` no eliminan datos físicamente; marcan el registro con el tiempo actual.
-   **Alerta**: Cada borrado lógico dispara un **Email de Alerta** vía SMTP al administrador.

### 📝 Auditoría de Transacciones (Logs)
Cada cambio en la base de Datos (CRUD) se registra doblemente:
1.  **Tabla `api_logs`**: Guarda quién hizo el cambio, en qué tabla, qué ID y el **JSON completo** de los datos enviados.
2.  **Archivo `logs/api_transactions.log`**: Un log histórico de acceso rápido con Método, Ruta, Status e IP.

### ⚙️ Configuración Dinámica (.env)
Ya no hay datos sensibles "hardcodeados" en el código. Toda la configuración se gestiona desde el archivo [`.env`](file:///var/www/html/api/.env).

### 🌐 Control de Entornos (Producción vs Desarrollo)
Puedes controlar la visibilidad de la documentación y el modo depuración desde el archivo `.env`:
-   **Desarrollo**: Usa `APP_ENV=development` para habilitar Swagger, OpenAPI y mensajes de error detallados.
-   **Producción**: Usa `APP_ENV=production` para deshabilitar automáticamente Swagger/OpenAPI y ocultar errores internos.
-   **Override**: Puedes forzar la activación de la documentación en cualquier entorno con `ENABLE_OPENAPI=true`.

---

## 🚀 Uso de la API (Recursos Dinámicos)

DASH-API expone automáticamente todas las tablas de tu base de Datos como recursos REST.

### 📋 Operaciones CRUD
Puedes acceder a cualquier tabla usando la ruta `/records/{nombre_tabla}`.
-   **GET** `/records/usuarios`: Lista registros (excluyendo borrados lógicos).
-   **GET** `/records/usuarios/1`: Detalle del registro con ID 1.
-   **POST** `/records/usuarios`: Crear un nuevo registro.
-   **PUT/PATCH** `/records/usuarios/1`: Actualizar el registro ID 1.
-   **DELETE** `/records/usuarios/1`: Realizar borrado lógico (Soft Delete).

### 🔍 Filtrado, Orden y Paginación
La API permite realizar consultas complejas mediante parámetros en la URL:

| Parámetro | Ejemplo | Descripción |
| :--- | :--- | :--- |
| **Filter** | `?filter=nombre,eq,Juan` | `eq` (igual), `neq` (distinto), `lt` (menor), `gt` (mayor). |
| **Order** | `?order=id,desc` | Ordena los resultados. |
| **Page** | `?page=1,20` | Paginación: Página 1, 20 registros por página. |
| **Search** | `?filter=nombre,like,A%` | Búsqueda por patrón (SQL LIKE). |

---

## ⚡ Documentación Interactiva (Swagger)

Accede a [swagger.html](file:///var/www/html/api/swagger.html) para probar los endpoints:
1.  Realiza un login para obtener el token.
2.  Usa el botón **"Authorize"** en la parte superior.
3.  Pega el token con el formato `Bearer <token>`.
4.  ¡Listo! Ya puedes consumir los recursos de forma segura.

---

> [!IMPORTANT]
> **Seguridad Crítica**: Nunca compartas tu `JWT_SECRET`. Si sospechas que ha sido comprometido, cámbialo en el `.env` y todos los tokens antiguos quedarán invalidados inmediatamente.

---
DASH API - Powered by César Rojas Basado en PHP CRUD API de Maurits van der Schee: maurits@vdschee.nl
