/**
 * API Helper Functions
 * Utilities for pagination, filtering, sorting, and uploads
 */

/**
 * Build pagination parameters for API requests
 * @param {number} page - Current page number (1-indexed)
 * @param {number} perPage - Items per page
 * @returns {object} Pagination parameters
 */
export function buildPaginationParams(page = 1, perPage = 15) {
    return {
        page,
        per_page: perPage,
    };
}

/**
 * Build filter parameters for API requests
 * @param {object} filters - Filter object with key-value pairs
 * @returns {object} Filter parameters
 * @example
 * buildFilterParams({ status: 'active', search: 'test' })
 * // Returns: { 'filter[status]': 'active', 'filter[search]': 'test' }
 */
export function buildFilterParams(filters = {}) {
    const params = {};
    Object.entries(filters).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            params[`filter[${key}]`] = value;
        }
    });
    return params;
}

/**
 * Build sort parameters for API requests
 * @param {string|string[]} sortBy - Field(s) to sort by
 * @param {string} direction - Sort direction ('asc' or 'desc')
 * @returns {object} Sort parameters
 * @example
 * buildSortParams('created_at', 'desc')
 * // Returns: { sort: '-created_at' }
 */
export function buildSortParams(sortBy, direction = 'asc') {
    if (!sortBy) return {};
    
    const fields = Array.isArray(sortBy) ? sortBy : [sortBy];
    const sortString = fields
        .map(field => direction === 'desc' ? `-${field}` : field)
        .join(',');
    
    return { sort: sortString };
}

/**
 * Build complete query parameters combining pagination, filters, and sorting
 * @param {object} options - Query options
 * @param {number} options.page - Current page
 * @param {number} options.perPage - Items per page
 * @param {object} options.filters - Filter object
 * @param {string|string[]} options.sortBy - Field(s) to sort by
 * @param {string} options.sortDirection - Sort direction
 * @returns {object} Complete query parameters
 */
export function buildQueryParams({ page, perPage, filters, sortBy, sortDirection } = {}) {
    return {
        ...buildPaginationParams(page, perPage),
        ...buildFilterParams(filters),
        ...buildSortParams(sortBy, sortDirection),
    };
}

/**
 * Parse pagination meta from API response
 * @param {object} meta - Meta object from API response
 * @returns {object} Parsed pagination info
 */
export function parsePaginationMeta(meta = {}) {
    return {
        currentPage: meta.current_page || 1,
        lastPage: meta.last_page || 1,
        perPage: meta.per_page || 15,
        total: meta.total || 0,
        from: meta.from || 0,
        to: meta.to || 0,
        hasNextPage: meta.current_page < meta.last_page,
        hasPrevPage: meta.current_page > 1,
    };
}

/**
 * Create FormData from an object for file uploads
 * @param {object} data - Data object to convert
 * @param {FormData} formData - Existing FormData instance (optional)
 * @param {string} parentKey - Parent key for nested objects (internal use)
 * @returns {FormData} FormData instance
 */
export function objectToFormData(data, formData = new FormData(), parentKey = null) {
    if (data && typeof data === 'object' && !(data instanceof File) && !(data instanceof Blob)) {
        Object.entries(data).forEach(([key, value]) => {
            const formKey = parentKey ? `${parentKey}[${key}]` : key;
            
            if (value === null || value === undefined) {
                return;
            }
            
            if (value instanceof File || value instanceof Blob) {
                formData.append(formKey, value);
            } else if (Array.isArray(value)) {
                value.forEach((item, index) => {
                    if (item instanceof File || item instanceof Blob) {
                        formData.append(`${formKey}[]`, item);
                    } else if (typeof item === 'object') {
                        objectToFormData({ [index]: item }, formData, `${formKey}`);
                    } else {
                        formData.append(`${formKey}[]`, item);
                    }
                });
            } else if (typeof value === 'object') {
                objectToFormData(value, formData, formKey);
            } else {
                formData.append(formKey, value);
            }
        });
    } else {
        formData.append(parentKey, data);
    }
    
    return formData;
}

/**
 * Upload file(s) with progress tracking
 * @param {string} url - Upload endpoint URL
 * @param {File|File[]} files - File or array of files
 * @param {object} additionalData - Additional data to send with upload
 * @param {function} onProgress - Progress callback (receives percentage)
 * @returns {Promise} Upload promise
 */
export async function uploadWithProgress(url, files, additionalData = {}, onProgress = null) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        
        const formData = new FormData();
        
        // Add files
        const fileArray = Array.isArray(files) ? files : [files];
        fileArray.forEach((file, index) => {
            formData.append(fileArray.length > 1 ? `files[${index}]` : 'file', file);
        });
        
        // Add additional data
        Object.entries(additionalData).forEach(([key, value]) => {
            if (value !== null && value !== undefined) {
                formData.append(key, value);
            }
        });
        
        // Progress tracking
        if (onProgress && typeof onProgress === 'function') {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    onProgress(percentComplete);
                }
            });
        }
        
        // Handle completion
        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    resolve(response);
                } catch (error) {
                    resolve(xhr.responseText);
                }
            } else {
                try {
                    const error = JSON.parse(xhr.responseText);
                    reject(error);
                } catch (e) {
                    reject(new Error(xhr.statusText));
                }
            }
        });
        
        // Handle errors
        xhr.addEventListener('error', () => {
            reject(new Error('Upload failed'));
        });
        
        xhr.addEventListener('abort', () => {
            reject(new Error('Upload aborted'));
        });
        
        // Send request
        xhr.open('POST', url);
        
        // Add auth token if available
        const token = localStorage.getItem('spa_tg_api_token');
        if (token) {
            xhr.setRequestHeader('Authorization', `Bearer ${token}`);
        }
        
        xhr.send(formData);
    });
}

/**
 * Extract validation errors from API error response
 * @param {object} error - API error object
 * @returns {object} Field-level error messages
 */
export function extractValidationErrors(error) {
    if (!error || !error.errors) return {};
    
    const errors = {};
    
    if (Array.isArray(error.errors)) {
        error.errors.forEach(err => {
            if (err.field) {
                errors[err.field] = err.message;
            }
        });
    } else if (typeof error.errors === 'object') {
        Object.entries(error.errors).forEach(([field, messages]) => {
            errors[field] = Array.isArray(messages) ? messages[0] : messages;
        });
    }
    
    return errors;
}

/**
 * Check if error is a validation error
 * @param {object} error - API error object
 * @returns {boolean} True if validation error
 */
export function isValidationError(error) {
    return error && error.status === 422 && error.errors;
}

/**
 * Check if error is an authentication error
 * @param {object} error - API error object
 * @returns {boolean} True if auth error
 */
export function isAuthError(error) {
    return error && (error.status === 401 || error.status === 403);
}