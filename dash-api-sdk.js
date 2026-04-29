/**
 * DASH-API SDK v1.0
 * SDK oficial para DASH-API
 * Características:
 * - Auto-descubrimiento de tablas
 * - Optimistic updates (UI instantánea)
 * - Long polling para tiempo real
 * - Manejo seguro de autenticación JWT
 * Compatible con cualquier hosting compartido
 */

(function(global) {
    'use strict';

    // ========== CLASE PRINCIPAL ==========
    class DASH_API {
        constructor(baseURL, options = {}) {
            this.baseURL = baseURL.replace(/\/$/, '');
            this.token = options.token || null;
            this.refreshToken = options.refreshToken || null;
            this.tables = null;
            this.initialized = false;
            
            // Configuración de tiempo real
            this.realtime = {
                enabled: options.realtime !== false,
                mode: options.realtimeMode || 'polling', // 'polling', 'optimistic', 'off'
                pollingInterval: options.pollingInterval || 3000,
                activePolls: new Map(),
                subscribers: new Map(),
                store: new Map(),
                pendingOperations: new Map(),
                lastSync: new Map()
            };
            
            // Configuración de seguridad
            this.security = {
                sanitizeResponses: options.sanitize !== false,
                tokenStorage: options.tokenStorage || 'memory', // 'memory', 'session'
                requestTimeout: options.requestTimeout || 30000,
                maxRetries: options.maxRetries || 2
            };
            
            // Almacenar token inicial
            if (this.token) {
                this._storeToken(this.token);
            }
            
            // Auto-descubrimiento
            if (options.autoDiscover !== false) {
                this.discover().catch(console.warn);
            }
        }
        
        // ========== MÉTODOS PRIVADOS ==========
        
        _storeToken(token) {
            this.token = token;
            if (this.security.tokenStorage === 'session' && typeof sessionStorage !== 'undefined') {
                sessionStorage.setItem('dash_api_token', token);
            } else if (this.security.tokenStorage === 'memory') {
                // Solo en memoria
            }
        }
        
        _getStoredToken() {
            if (this.token) return this.token;
            if (this.security.tokenStorage === 'session' && typeof sessionStorage !== 'undefined') {
                return sessionStorage.getItem('dash_api_token');
            }
            return null;
        }
        
        _clearToken() {
            this.token = null;
            if (typeof sessionStorage !== 'undefined') {
                sessionStorage.removeItem('dash_api_token');
            }
        }
        
        async _request(endpoint, method = 'GET', data = null, params = {}, retryCount = 0) {
            let url = `${this.baseURL}/${endpoint}`;
            
            // Construir query string
            if (Object.keys(params).length > 0) {
                const filteredParams = {};
                for (const [key, value] of Object.entries(params)) {
                    if (value !== undefined && value !== null && value !== '') {
                        filteredParams[key] = value;
                    }
                }
                if (Object.keys(filteredParams).length) {
                    url += `?${new URLSearchParams(filteredParams).toString()}`;
                }
            }
            
            const headers = {
                'Content-Type': 'application/json'
            };
            
            const token = this._getStoredToken();
            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }
            
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), this.security.requestTimeout);
            
            const config = {
                method,
                headers,
                signal: controller.signal
            };
            
            if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
                config.body = JSON.stringify(data);
            }
            
            try {
                const response = await fetch(url, config);
                clearTimeout(timeoutId);
                
                // Manejo especial para 401 (token expirado)
                if (response.status === 401 && retryCount < this.security.maxRetries && this.refreshToken) {
                    const refreshed = await this._refreshToken();
                    if (refreshed) {
                        return this._request(endpoint, method, data, params, retryCount + 1);
                    }
                }
                
                const result = await response.json();
                
                if (!response.ok) {
                    const error = new Error(result.message || `HTTP ${response.status}`);
                    error.code = result.code;
                    error.details = result.details;
                    error.status = response.status;
                    throw error;
                }
                
                // Sanitizar respuesta si es necesario
                if (this.security.sanitizeResponses) {
                    return this._sanitize(result);
                }
                
                return result;
                
            } catch (error) {
                clearTimeout(timeoutId);
                if (error.name === 'AbortError') {
                    throw new Error('Request timeout');
                }
                throw error;
            }
        }
        
        _sanitize(obj) {
            if (typeof obj === 'string') {
                return obj
                    .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
                    .replace(/javascript:/gi, '')
                    .replace(/on\w+\s*=/gi, '');
            }
            if (typeof obj === 'object' && obj !== null) {
                if (Array.isArray(obj)) {
                    return obj.map(item => this._sanitize(item));
                }
                const sanitized = {};
                for (const [key, value] of Object.entries(obj)) {
                    sanitized[key] = this._sanitize(value);
                }
                return sanitized;
            }
            return obj;
        }
        
        async _refreshToken() {
            if (!this.refreshToken) return false;
            
            try {
                const response = await fetch(`${this.baseURL}/refresh`, {
                    method: 'POST',
                    headers: { 'Authorization': `Bearer ${this.refreshToken}` }
                });
                
                if (response.ok) {
                    const data = await response.json();
                    this._storeToken(data.access_token);
                    this.refreshToken = data.refresh_token;
                    return true;
                }
            } catch (e) {
                console.error('Refresh token failed:', e);
            }
            return false;
        }
        
        // ========== AUTO-DESCUBRIMIENTO ==========
        
        async discover(forceRefresh = false) {
            if (this.initialized && !forceRefresh) return this.tables;
            
            try {
                const response = await this._request('columns', 'GET');
                
                if (response.tables && Array.isArray(response.tables)) {
                    this.tables = {};
                    
                    for (const tableInfo of response.tables) {
                        const tableName = tableInfo.alias || tableInfo.name;
                        const columns = {};
                        
                        if (tableInfo.columns && Array.isArray(tableInfo.columns)) {
                            for (const col of tableInfo.columns) {
                                const colName = col.alias || col.name;
                                columns[colName] = {
                                    name: col.name,
                                    type: col.type,
                                    nullable: col.nullable || false,
                                    pk: col.pk || false,
                                    fk: col.fk || null
                                };
                            }
                        }
                        
                        this.tables[tableName] = {
                            name: tableName,
                            realName: tableInfo.name,
                            type: tableInfo.type || 'table',
                            columns: columns,
                            pk: Object.keys(columns).find(k => columns[k].pk) || null
                        };
                        
                        this._createTableMethods(tableName);
                    }
                    
                    this.initialized = true;
                    
                    // Iniciar tiempo real si está habilitado
                    if (this.realtime.enabled && this.realtime.mode === 'polling') {
                        this._startPollingForAllTables();
                    }
                }
                
                return this.tables;
                
            } catch (error) {
                console.error('Discovery failed:', error);
                throw error;
            }
        }
        
        _createTableMethods(tableName) {
            if (this[tableName]) return;
            
            const self = this;
            
            this[tableName] = {
                // Listar registros con filtros
                listar: async (options = {}) => {
                    const params = {};
                    
                    if (options.filter) params.filter = options.filter;
                    if (options.size) params.size = options.size;
                    if (options.page) params.page = options.page;
                    if (options.order) params.order = options.order;
                    if (options.include) params.include = options.include;
                    if (options.exclude) params.exclude = options.exclude;
                    if (options.join) params.join = options.join;
                    
                    // Soporte para filtros estilo objeto
                    if (options.where && typeof options.where === 'object') {
                        const filters = [];
                        let i = 0;
                        for (const [col, val] of Object.entries(options.where)) {
                            filters.push(`${col},eq,${val}`);
                        }
                        if (filters.length) params.filter = filters;
                    }
                    
                    // Usar caché si está disponible en tiempo real
                    if (self.realtime.enabled && self.realtime.store.has(tableName)) {
                        const cached = self.realtime.store.get(tableName);
                        if (cached && (!options.forceRefresh)) {
                            return { records: cached, cached: true };
                        }
                    }
                    
                    const result = await self._request(`records/${tableName}`, 'GET', null, params);
                    
                    // Actualizar caché local
                    if (self.realtime.enabled && result.records) {
                        self.realtime.store.set(tableName, result.records);
                        self.realtime.lastSync.set(tableName, Date.now());
                    }
                    
                    return result;
                },
                
                // Obtener un registro por ID
                obtener: async (id, options = {}) => {
                    const params = {};
                    if (options.include) params.include = options.include;
                    return await self._request(`records/${tableName}/${id}`, 'GET', null, params);
                },
                
                // Crear registro (con optimistic update)
                crear: async (data) => {
                    // Generar ID temporal para optimistic update
                    const tempId = `temp_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
                    const optimisticRecord = {
                        ...data,
                        id: tempId,
                        _optimistic: true,
                        _pending: true,
                        _createdAt: new Date().toISOString()
                    };
                    
                    // Optimistic update: actualizar UI inmediatamente
                    if (self.realtime.enabled && self.realtime.mode === 'optimistic') {
                        const currentData = self.realtime.store.get(tableName) || [];
                        self.realtime.store.set(tableName, [optimisticRecord, ...currentData]);
                        self._notifySubscribers(tableName);
                        
                        // Registrar operación pendiente
                        self.realtime.pendingOperations.set(tempId, {
                            type: 'create',
                            table: tableName,
                            data: data,
                            tempId: tempId,
                            timestamp: Date.now()
                        });
                    }
                    
                    try {
                        const result = await self._request(`records/${tableName}`, 'POST', data);
                        
                        // Actualizar optimistic con resultado real
                        if (self.realtime.enabled && self.realtime.mode === 'optimistic') {
                            const currentData = self.realtime.store.get(tableName) || [];
                            const updatedData = currentData.map(record => 
                                record.id === tempId ? { ...result, _synced: true } : record
                            );
                            self.realtime.store.set(tableName, updatedData);
                            self.realtime.pendingOperations.delete(tempId);
                            self._notifySubscribers(tableName);
                        }
                        
                        // Notificar cambio a otros suscriptores
                        if (self.realtime.enabled) {
                            self._emitChange(tableName, 'created', result);
                        }
                        
                        return result;
                        
                    } catch (error) {
                        // Revertir optimistic update en caso de error
                        if (self.realtime.enabled && self.realtime.mode === 'optimistic') {
                            const currentData = self.realtime.store.get(tableName) || [];
                            const revertedData = currentData.filter(r => r.id !== tempId);
                            self.realtime.store.set(tableName, revertedData);
                            self.realtime.pendingOperations.delete(tempId);
                            self._notifySubscribers(tableName);
                        }
                        throw error;
                    }
                },
                
                // Actualizar registro (con optimistic update)
                actualizar: async (id, data) => {
                    let oldRecord = null;
                    
                    // Optimistic update
                    if (self.realtime.enabled && self.realtime.mode === 'optimistic') {
                        const currentData = self.realtime.store.get(tableName) || [];
                        oldRecord = currentData.find(r => r.id == id);
                        
                        if (oldRecord) {
                            const updatedData = currentData.map(record =>
                                record.id == id ? { ...record, ...data, _updating: true } : record
                            );
                            self.realtime.store.set(tableName, updatedData);
                            self._notifySubscribers(tableName);
                            
                            self.realtime.pendingOperations.set(`${tableName}_${id}`, {
                                type: 'update',
                                table: tableName,
                                id: id,
                                data: data,
                                oldRecord: oldRecord,
                                timestamp: Date.now()
                            });
                        }
                    }
                    
                    try {
                        const result = await self._request(`records/${tableName}/${id}`, 'PUT', data);
                        
                        if (self.realtime.enabled && self.realtime.mode === 'optimistic') {
                            const currentData = self.realtime.store.get(tableName) || [];
                            const finalData = currentData.map(record =>
                                record.id == id ? { ...record, ...result, _updating: false } : record
                            );
                            self.realtime.store.set(tableName, finalData);
                            self.realtime.pendingOperations.delete(`${tableName}_${id}`);
                            self._notifySubscribers(tableName);
                        }
                        
                        self._emitChange(tableName, 'updated', result);
                        return result;
                        
                    } catch (error) {
                        // Revertir
                        if (self.realtime.enabled && self.realtime.mode === 'optimistic' && oldRecord) {
                            const currentData = self.realtime.store.get(tableName) || [];
                            const revertedData = currentData.map(record =>
                                record.id == id ? oldRecord : record
                            );
                            self.realtime.store.set(tableName, revertedData);
                            self.realtime.pendingOperations.delete(`${tableName}_${id}`);
                            self._notifySubscribers(tableName);
                        }
                        throw error;
                    }
                },
                
                // Actualización parcial (PATCH)
                actualizarParcial: async (id, data) => {
                    return await self._request(`records/${tableName}/${id}`, 'PATCH', data);
                },
                
                // Eliminar registro
                eliminar: async (id) => {
                    let oldRecord = null;
                    
                    if (self.realtime.enabled && self.realtime.mode === 'optimistic') {
                        const currentData = self.realtime.store.get(tableName) || [];
                        oldRecord = currentData.find(r => r.id == id);
                        
                        if (oldRecord) {
                            const filteredData = currentData.filter(r => r.id != id);
                            self.realtime.store.set(tableName, filteredData);
                            self._notifySubscribers(tableName);
                        }
                    }
                    
                    try {
                        const result = await self._request(`records/${tableName}/${id}`, 'DELETE');
                        self._emitChange(tableName, 'deleted', { id });
                        return result;
                    } catch (error) {
                        // Revertir
                        if (self.realtime.enabled && self.realtime.mode === 'optimistic' && oldRecord) {
                            const currentData = self.realtime.store.get(tableName) || [];
                            currentData.unshift(oldRecord);
                            self.realtime.store.set(tableName, currentData);
                            self._notifySubscribers(tableName);
                        }
                        throw error;
                    }
                },
                
                // Suscribirse a cambios en tiempo real
                on: (callback) => {
                    return self._subscribe(tableName, callback);
                },
                
                // Obtener información de la tabla
                info: () => self.tables?.[tableName] || null,
                
                // Obtener columnas
                columnas: () => Object.keys(self.tables?.[tableName]?.columns || {})
            };
            
            // Alias en inglés
            this[tableName].find = this[tableName].listar;
            this[tableName].get = this[tableName].obtener;
            this[tableName].insert = this[tableName].crear;
            this[tableName].update = this[tableName].actualizar;
            this[tableName].delete = this[tableName].eliminar;
            this[tableName].subscribe = this[tableName].on;
        }
        
        // ========== TIEMPO REAL ==========
        
        _subscribe(tableName, callback) {
            if (!this.realtime.subscribers.has(tableName)) {
                this.realtime.subscribers.set(tableName, []);
                
                // Iniciar polling para esta tabla si está en modo polling
                if (this.realtime.enabled && this.realtime.mode === 'polling') {
                    this._startPolling(tableName);
                }
                
                // Cargar datos iniciales
                this[tableName].listar().then(result => {
                    const records = result.records || result;
                    if (records) {
                        this.realtime.store.set(tableName, records);
                        callback(records, { type: 'snapshot', timestamp: Date.now() });
                    }
                }).catch(console.error);
            }
            
            const subscriber = { callback, id: Math.random().toString(36).substr(2, 9) };
            this.realtime.subscribers.get(tableName).push(subscriber);
            
            // Devolver función para desuscribirse
            return () => {
                const subs = this.realtime.subscribers.get(tableName);
                if (subs) {
                    const index = subs.findIndex(s => s.id === subscriber.id);
                    if (index !== -1) subs.splice(index, 1);
                    
                    if (subs.length === 0 && this.realtime.mode === 'polling') {
                        this._stopPolling(tableName);
                    }
                }
            };
        }
        
        _notifySubscribers(tableName) {
            const subs = this.realtime.subscribers.get(tableName);
            const data = this.realtime.store.get(tableName);
            
            if (subs && data) {
                subs.forEach(sub => {
                    try {
                        sub.callback(data, { type: 'update', timestamp: Date.now() });
                    } catch (e) {
                        console.error('Subscriber callback error:', e);
                    }
                });
            }
        }
        
        _emitChange(tableName, action, data) {
            // Notificar a suscriptores mediante polling (la próxima iteración lo capturará)
            // También podemos emitir un evento para que el polling lo detecte más rápido
            if (this.realtime.mode === 'polling') {
                // Marcar que necesita actualización inmediata
                this.realtime.lastSync.delete(tableName);
            } else if (this.realtime.mode === 'optimistic') {
                // En modo optimista ya actualizamos la UI
                this._notifySubscribers(tableName);
            }
        }
        
        _startPolling(tableName) {
            if (this.realtime.activePolls.has(tableName)) return;
            
            const poll = async () => {
                if (!this.realtime.activePolls.has(tableName)) return;
                
                try {
                    // Usar timestamp para detectar cambios
                    const lastSync = this.realtime.lastSync.get(tableName) || 0;
                    const result = await this[tableName].listar({ forceRefresh: true });
                    const newData = result.records || result;
                    const oldData = this.realtime.store.get(tableName);
                    
                    // Verificar si hubo cambios (comparación profunda simplificada)
                    if (JSON.stringify(newData) !== JSON.stringify(oldData)) {
                        this.realtime.store.set(tableName, newData);
                        this.realtime.lastSync.set(tableName, Date.now());
                        this._notifySubscribers(tableName);
                    }
                    
                } catch (error) {
                    console.error(`Polling error for ${tableName}:`, error);
                }
                
                // Programar próxima iteración
                if (this.realtime.activePolls.has(tableName)) {
                    const interval = this.realtime.pollingInterval;
                    setTimeout(poll, interval);
                }
            };
            
            this.realtime.activePolls.set(tableName, true);
            poll();
        }
        
        _stopPolling(tableName) {
            this.realtime.activePolls.delete(tableName);
        }
        
        _startPollingForAllTables() {
            if (this.tables) {
                for (const tableName of Object.keys(this.tables)) {
                    if (this.realtime.subscribers.has(tableName)) {
                        this._startPolling(tableName);
                    }
                }
            }
        }
        
        // ========== AUTENTICACIÓN ==========
        
        auth = {
            login: async (email, password) => {
                const result = await this._request('login', 'POST', { email, password });
                
                if (result.access_token) {
                    this._storeToken(result.access_token);
                    this.refreshToken = result.refresh_token || null;
                } else if (result.token) {
                    this._storeToken(result.token);
                }
                
                return result;
            },
            
            logout: async () => {
                try {
                    await this._request('logout', 'POST');
                } catch (e) {
                    // Ignorar error en logout
                }
                this._clearToken();
                this.refreshToken = null;
                
                // Limpiar caché local
                this.realtime.store.clear();
                this.realtime.subscribers.clear();
                this.realtime.activePolls.clear();
            },
            
            me: async () => {
                try {
                    return await this._request('me', 'GET');
                } catch {
                    return null;
                }
            },
            
            register: async (userData) => {
                return await this._request('register', 'POST', userData);
            },
            
            changePassword: async (username, password, newPassword) => {
                return await this._request('password', 'POST', { username, password, newPassword });
            },
            
            isAuthenticated: () => {
                return !!this._getStoredToken();
            },
            
            getToken: () => this._getStoredToken()
        };
        
        // ========== MÉTODOS GENERALES ==========
        
        async getDatabaseStructure() {
            if (!this.initialized) await this.discover();
            return this.tables;
        }
        
        async getTableStructure(tableName) {
            if (!this.initialized) await this.discover();
            return this.tables?.[tableName] || null;
        }
        
        async ping() {
            try {
                return await this._request('status/ping', 'GET');
            } catch {
                return null;
            }
        }
        
        async clearCache() {
            return await this._request('cache/clear', 'GET');
        }
        
        stopRealtime() {
            for (const [tableName] of this.realtime.activePolls) {
                this._stopPolling(tableName);
            }
            this.realtime.enabled = false;
        }
        
        startRealtime(interval = 3000) {
            this.realtime.enabled = true;
            this.realtime.pollingInterval = interval;
            this._startPollingForAllTables();
        }
    }
    
    // ========== EXPORTAR ==========
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { DASH_API };
    } else if (typeof define === 'function' && define.amd) {
        define([], function() { return DASH_API; });
    } else {
        global.DASH_API = DASH_API;
    }
    
})(typeof window !== 'undefined' ? window : typeof global !== 'undefined' ? global : this);