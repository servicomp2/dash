# 📘 DASH-API SDK Manual Oficial

> SDK JavaScript para DASH-API con soporte de tiempo real, optimistic updates y auto-descubrimiento de tablas.

## 📋 Tabla de Contenidos

- [Instalación](#-instalación)
- [Configuración Inicial](#-configuración-inicial)
- [Autenticación](#-autenticación)
- [Operaciones CRUD](#-operaciones-crud)
- [Tiempo Real](#-tiempo-real)
- [Filtros Avanzados](#-filtros-avanzados)
- [Seguridad](#-seguridad)
- [Manejo de Errores](#-manejo-de-errores)
- [Ejemplos Prácticos](#-ejemplos-prácticos)
- [Referencia de API](#-referencia-de-api)
- [Preguntas Frecuentes](#-preguntas-frecuentes)

---

## 🚀 Instalación

### Opción 1: Script directo

```html
<script src="https://tudominio.com/sdk/dash-sdk.js"></script>
```

### Opción 2: Descarga local

```bash
# Guarda el archivo en tu proyecto
cp dash-sdk.js /tu-proyecto/js/
```

### Opción 3: NPM (próximamente)

```bash
npm install dash-api-sdk
```

---

## ⚙️ Configuración Inicial

### Configuración básica

```javascript
const api = new DASH_API('https://tudominio.com/api');
```

### Configuración completa

```javascript
const api = new DASH_API('https://tudominio.com/api', {
    autoDiscover: true,           // Auto-descubrimiento (default: true)
    realtime: true,               // Habilitar tiempo real (default: false)
    realtimeMode: 'polling',      // 'polling' o 'optimistic'
    pollingInterval: 3000,        // Intervalo de polling en ms
    tokenStorage: 'memory',       // 'memory' o 'session'
    sanitize: true,               // Sanitizar respuestas
    requestTimeout: 30000,        // Timeout en ms
    maxRetries: 2                 // Intentos máximos
});
```

### Parámetros de configuración

| Parámetro | Tipo | Default | Descripción |
|-----------|------|---------|-------------|
| autoDiscover | boolean | true | Detecta tablas automáticamente |
| realtime | boolean | false | Habilita modo tiempo real |
| realtimeMode | string | 'polling' | 'polling' o 'optimistic' |
| pollingInterval | number | 3000 | Intervalo de actualización (ms) |
| tokenStorage | string | 'memory' | 'memory' o 'session' |
| sanitize | boolean | true | Previene ataques XSS |
| requestTimeout | number | 30000 | Timeout de peticiones |
| maxRetries | number | 2 | Reintentos en caso de error |

---

## 🔐 Autenticación

### Login de usuario

```javascript
try {
    const resultado = await api.auth.login('usuario@ejemplo.com', 'mi_password');
    console.log('Login exitoso:', resultado);
} catch (error) {
    console.error('Error:', error.message);
}
```

### Verificar autenticación

```javascript
if (api.auth.isAuthenticated()) {
    const usuario = await api.auth.me();
    console.log('Usuario actual:', usuario);
}
```

### Cerrar sesión

```javascript
await api.auth.logout();
```

### Registrar usuario

```javascript
const nuevoUsuario = await api.auth.register({
    email: 'nuevo@ejemplo.com',
    password: 'MiPassword123',
    nombre: 'Juan Pérez'
});
```

### Cambiar contraseña

```javascript
await api.auth.changePassword('usuario@ejemplo.com', 'vieja_pass', 'nueva_pass123');
```

---

## 📝 Operaciones CRUD

El SDK descubre automáticamente todas tus tablas. Ejemplo con una tabla `usuarios`:

### Listar registros

```javascript
// Todos los usuarios
const usuarios = await api.usuarios.listar();

// Con paginación
const resultados = await api.usuarios.listar({
    size: 10,   // 10 por página
    page: 1     // página 1
});

// Ordenados
const ordenados = await api.usuarios.listar({
    order: 'nombre,asc'
});
```

### Obtener un registro

```javascript
const usuario = await api.usuarios.obtener(1);
```

### Crear registro

```javascript
const nuevo = await api.usuarios.crear({
    nombre: 'Ana García',
    email: 'ana@ejemplo.com',
    password: '123456'
});
```

### Actualizar registro

```javascript
const actualizado = await api.usuarios.actualizar(1, {
    nombre: 'Ana García Actualizada'
});
```

### Actualización parcial

```javascript
const resultado = await api.usuarios.actualizarParcial(1, {
    telefono: '123456789'
});
```

### Eliminar registro

```javascript
await api.usuarios.eliminar(1);
```

### Alias en inglés

```javascript
api.usuarios.find()     // = listar()
api.usuarios.get()      // = obtener()
api.usuarios.insert()   // = crear()
api.usuarios.update()   // = actualizar()
api.usuarios.delete()   // = eliminar()
```

---

## 🔄 Tiempo Real

### Modo Polling (Recomendado para hosting compartido)

```javascript
const api = new DASH_API('https://tudominio.com/api', {
    realtime: true,
    realtimeMode: 'polling',
    pollingInterval: 2000  // actualiza cada 2 segundos
});

await api.auth.login('admin@ejemplo.com', 'password');
await api.discover();

// Suscribirse a cambios
const unsubscribe = api.mensajes.on((mensajes) => {
    console.log('Mensajes actualizados:', mensajes);
    renderizarMensajes(mensajes);
});

// Crear mensaje
await api.mensajes.crear({ texto: 'Hola mundo', usuario_id: 1 });

// Dejar de escuchar
unsubscribe();
```

### Modo Optimistic (UI instantánea)

```javascript
const api = new DASH_API('https://tudominio.com/api', {
    realtime: true,
    realtimeMode: 'optimistic'
});

api.discover().then(() => {
    api.usuarios.on((usuarios) => {
        renderizarLista(usuarios);
    });
    
    // Este usuario aparece inmediatamente en la UI
    await api.usuarios.crear({ nombre: 'Juan', email: 'juan@mail.com' });
});
```

### Control manual del tiempo real

```javascript
// Detener tiempo real
api.stopRealtime();

// Reanudar
api.startRealtime(5000);  // intervalo de 5 segundos
```

---

## 🔍 Filtros Avanzados

### Filtros simples

```javascript
// Usuarios activos
const activos = await api.usuarios.listar({
    filter: ['activo,eq,1']
});

// Usuarios con email que contiene '@gmail.com'
const gmail = await api.usuarios.listar({
    filter: ['email,cs,@gmail.com']
});
```

### Múltiples filtros

```javascript
const resultados = await api.usuarios.listar({
    filter: [
        'edad,ge,18',      // edad >= 18
        'activo,eq,1',     // activo = 1
        'nombre,cs,Juan'   // nombre contiene "Juan"
    ]
});
```

### Filtros estilo objeto

```javascript
const usuarios = await api.usuarios.listar({
    where: {
        email: 'admin@ejemplo.com',
        activo: 1
    }
});
```

### Operadores disponibles

| Operador | Descripción | Ejemplo |
|----------|-------------|---------|
| eq | Igual | `edad,eq,18` |
| lt | Menor que | `edad,lt,18` |
| le | Menor o igual | `edad,le,18` |
| gt | Mayor que | `edad,gt,18` |
| ge | Mayor o igual | `edad,ge,18` |
| cs | Contiene | `nombre,cs,Juan` |
| sw | Empieza con | `nombre,sw,Jo` |
| ew | Termina con | `nombre,ew,n` |
| bt | Entre | `edad,bt,18,30` |
| in | En lista | `id,in,1,2,3` |
| is | Es nulo | `deleted_at,is,null` |

---

## 🛡️ Seguridad

### Características de seguridad incluidas

- **Sanitización de respuestas**: Previene ataques XSS
- **Tokens en memoria**: No persisten en localStorage (configurable)
- **Timeout automático**: Previene peticiones colgadas
- **Reintentos controlados**: Maneja errores de red
- **HTTPS enforcement**: Recomendado en producción

### Configuración segura recomendada

```javascript
const api = new DASH_API('https://tudominio.com/api', {
    tokenStorage: 'memory',    // No persiste el token
    sanitize: true,            // Sanitiza respuestas
    requestTimeout: 10000,     // Timeout agresivo
    maxRetries: 1              // Solo un reintento
});
```

### Buenas prácticas

```javascript
// ✅ HACER: Validar en backend siempre
// ❌ NO HACER: Confiar en datos del frontend

// ✅ HACER: Usar tokens de corta duración
// ❌ NO HACER: Almacenar contraseñas en el SDK

// ✅ HACER: Cerrar sesión en eventos sospechosos
if (actividadSospechosa) {
    await api.auth.logout();
}
```

---

## ❌ Manejo de Errores

### Try-Catch básico

```javascript
try {
    const usuarios = await api.usuarios.listar();
} catch (error) {
    console.error('Error:', error.message);
    console.log('Código:', error.code);
    console.log('Detalles:', error.details);
}
```

### Códigos de error comunes

| Código | Descripción |
|--------|-------------|
| 1001 | Tabla no encontrada |
| 1003 | Registro no encontrado |
| 1011 | Autenticación requerida |
| 1012 | Autenticación fallida |
| 1013 | Validación de input fallida |

### Manejador global de errores

```javascript
const api = new DASH_API('https://tudominio.com/api', {
    onError: (error) => {
        console.error('Error global:', error);
        // Mostrar notificación al usuario
        mostrarNotificacion('Error: ' + error.message);
    }
});
```

---

## 📱 Ejemplos Prácticos

### Ejemplo 1: Lista de usuarios con tiempo real

```html
<!DOCTYPE html>
<html>
<head>
    <title>Usuarios en Tiempo Real</title>
</head>
<body>
    <div id="app">
        <h1>Usuarios</h1>
        <input type="text" id="nombre" placeholder="Nombre">
        <input type="email" id="email" placeholder="Email">
        <button onclick="agregarUsuario()">Agregar</button>
        <ul id="lista"></ul>
    </div>

    <script src="dash-sdk.js"></script>
    <script>
        const api = new DASH_API('https://tudominio.com/api', {
            realtime: true,
            realtimeMode: 'polling',
            pollingInterval: 2000
        });

        async function init() {
            await api.auth.login('admin@ejemplo.com', 'password');
            await api.discover();
            
            api.usuarios.on((usuarios) => {
                const lista = document.getElementById('lista');
                lista.innerHTML = usuarios.map(u => 
                    `<li>${u.nombre} - ${u.email}</li>`
                ).join('');
            });
        }

        async function agregarUsuario() {
            const nombre = document.getElementById('nombre').value;
            const email = document.getElementById('email').value;
            
            await api.usuarios.crear({ nombre, email });
            
            // Limpiar formulario
            document.getElementById('nombre').value = '';
            document.getElementById('email').value = '';
        }

        init();
    </script>
</body>
</html>
```

### Ejemplo 2: React Hook personalizado

```jsx
// hooks/useRealtimeTable.js
import { useState, useEffect } from 'react';
import { DASH_API } from './dash-sdk';

const api = new DASH_API(process.env.REACT_APP_API_URL);

export function useRealtimeTable(tableName) {
    const [data, setData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        let unsubscribe = null;

        const init = async () => {
            try {
                await api.discover();
                unsubscribe = api[tableName].on(setData);
                setLoading(false);
            } catch (err) {
                setError(err);
                setLoading(false);
            }
        };

        init();

        return () => {
            if (unsubscribe) unsubscribe();
        };
    }, [tableName]);

    return { data, loading, error, api: api[tableName] };
}

// Uso en componente
function UsuariosList() {
    const { data: usuarios, loading, api } = useRealtimeTable('usuarios');

    if (loading) return <div>Cargando...</div>;

    return (
        <div>
            {usuarios.map(u => <div key={u.id}>{u.nombre}</div>)}
            <button onClick={() => api.crear({ nombre: 'Nuevo' })}>
                Agregar
            </button>
        </div>
    );
}
```

### Ejemplo 3: Vue Composition API

```vue
<template>
  <div>
    <div v-if="loading">Cargando...</div>
    <div v-for="usuario in usuarios" :key="usuario.id">
      {{ usuario.nombre }}
    </div>
    <button @click="agregarUsuario">Agregar</button>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { DASH_API } from './dash-sdk';

const api = new DASH_API('https://tudominio.com/api');
const usuarios = ref([]);
const loading = ref(true);
let unsubscribe = null;

onMounted(async () => {
    await api.auth.login('admin@ejemplo.com', 'password');
    await api.discover();
    
    unsubscribe = api.usuarios.on((data) => {
        usuarios.value = data;
        loading.value = false;
    });
});

const agregarUsuario = async () => {
    await api.usuarios.crear({ nombre: 'Nuevo Usuario' });
};

onUnmounted(() => {
    if (unsubscribe) unsubscribe();
});
</script>
```

---

## 📚 Referencia de API

### Clase DASH_API

#### Constructor

```javascript
new DASH_API(baseURL, options)
```

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| baseURL | string | URL base de tu API (ej: https://tudominio.com/api) |
| options | object | Opciones de configuración |

#### Métodos principales

| Método | Descripción |
|--------|-------------|
| `discover()` | Descubre todas las tablas automáticamente |
| `getDatabaseStructure()` | Obtiene estructura completa de la BD |
| `getTableStructure(tableName)` | Obtiene estructura de una tabla |
| `ping()` | Verifica conectividad con el servidor |
| `clearCache()` | Limpia caché del servidor |
| `stopRealtime()` | Detiene actualizaciones en tiempo real |
| `startRealtime(interval)` | Inicia tiempo real con intervalo personalizado |

#### auth (objeto)

| Método | Descripción |
|--------|-------------|
| `login(email, password)` | Inicia sesión |
| `logout()` | Cierra sesión |
| `me()` | Obtiene usuario actual |
| `register(userData)` | Registra nuevo usuario |
| `changePassword(username, oldPass, newPass)` | Cambia contraseña |
| `isAuthenticated()` | Verifica si hay sesión activa |

#### [tableName] (objeto dinámico)

| Método | Descripción |
|--------|-------------|
| `listar(options)` | Lista registros |
| `obtener(id)` | Obtiene un registro |
| `crear(data)` | Crea un registro |
| `actualizar(id, data)` | Actualiza un registro |
| `actualizarParcial(id, data)` | Actualización parcial |
| `eliminar(id)` | Elimina un registro |
| `on(callback)` | Suscribe a cambios en tiempo real |
| `info()` | Obtiene metadata de la tabla |
| `columnas()` | Lista las columnas |

---

## ❓ Preguntas Frecuentes

### ¿Funciona en hosting compartido?

Sí. El SDK usa polling (peticiones HTTP normales) que funcionan en cualquier hosting que soporte PHP y MySQL.

### ¿Necesito modificar mi API?

No. El SDK funciona exactamente con tu DASH-API actual sin cambios.

### ¿Qué tan seguro es?

El SDK incluye sanitización de respuestas (previene XSS), manejo seguro de tokens y timeout automático. La seguridad principal debe estar en tu backend.

### ¿Cómo maneja los tokens?

Por defecto los guarda en memoria (no persisten). Puedes configurar `tokenStorage: 'session'` para persistir en sessionStorage.

### ¿Puedo usarlo con React/Vue/Angular?

Sí. Funciona con cualquier framework JavaScript.

### ¿El tiempo real consume muchos recursos?

El polling es configurable. Con intervalos de 3-5 segundos el consumo es mínimo.

### ¿Qué pasa si la API cambia?

El SDK redescubre la estructura automáticamente. Solo llama a `api.discover()` nuevamente.

---

## 📄 Licencia

MIT

## 👨‍💻 Autor

Creado por César Rojas para DASH-API

## 🤝 Soporte

Para reportar problemas o sugerir mejoras, aescribe a servicomp.cesar@gmail.com.