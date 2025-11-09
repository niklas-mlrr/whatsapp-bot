import 'dotenv/config';
import path from 'path';
import fs from 'fs';

/**
 * Configuration object for the WhatsApp bot
 * @type {Object}
 */
const config = {
    // Server Configuration
    port: parseInt(process.env.PORT, 10) || 3000,
    host: process.env.HOST || '0.0.0.0',
    nodeEnv: process.env.NODE_ENV || 'development',
    isProduction: process.env.NODE_ENV === 'production',
    isDevelopment: process.env.NODE_ENV === 'development',
    isTest: process.env.NODE_ENV === 'test',
    
    // WhatsApp Configuration
    whatsapp: {
        clientName: process.env.WHATSAPP_CLIENT_NAME || 'WhatsApp Bot',
        authDir: process.env.WHATSAPP_AUTH_DIR || './baileys_auth_info',
        headless: process.env.WHATSAPP_HEADLESS !== 'false', // true by default
        qrTimeoutMs: parseInt(process.env.WHATSAPP_QR_TIMEOUT_MS, 10) || 60000,
        maxRetries: parseInt(process.env.WHATSAPP_MAX_RETRIES, 10) || 5,
    },
    
    // Backend API Configuration
    backend: {
        apiUrl: process.env.BACKEND_API_URL || 'http://192.168.178.84:8000/api/whatsapp-webhook',
        apiKey: process.env.BACKEND_API_KEY || '',
        timeoutMs: parseInt(process.env.BACKEND_TIMEOUT_MS, 10) || 30000, // Increased to 30s for large groups/communities
        maxRetries: parseInt(process.env.BACKEND_MAX_RETRIES, 10) || 3,
        retryDelayMs: parseInt(process.env.BACKEND_RETRY_DELAY_MS, 10) || 1000,
    },
    
    // Logging Configuration
    logging: {
        level: (() => {
            const logLevel = process.env.LOG_LEVEL || (process.env.NODE_ENV === 'production' ? 'info' : 'debug');
            // Map 'warning' to 'warn' for Pino compatibility
            return logLevel === 'warning' ? 'warn' : logLevel;
        })(),
        logToFile: process.env.LOG_TO_FILE === 'true' || process.env.NODE_ENV === 'production',
        logFilePath: (() => {
            // Create year/month/day-based filename: app-DD.log
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');

            // If LOG_FILE_PATH is set and includes a filename, extract the directory
            const envPath = process.env.LOG_FILE_PATH;
            let baseLogDir;

            if (envPath) {
                // Check if it ends with .log (is a file path)
                if (envPath.endsWith('.log')) {
                    baseLogDir = path.dirname(envPath);
                } else {
                    baseLogDir = envPath;
                }
            } else {
                baseLogDir = path.join(process.cwd(), 'logs');
            }

            return path.join(baseLogDir, String(year), month, `app-${day}.log`);
        })(),
        filePatternPath: (() => {
            // Rotation pattern: logs/%Y/%m/app-%d.log (pino-roll strftime spec)
            const envPath = process.env.LOG_FILE_PATH;
            let baseLogDir;

            if (envPath) {
                if (envPath.endsWith('.log')) {
                    baseLogDir = path.dirname(envPath);
                } else {
                    baseLogDir = envPath;
                }
            } else {
                baseLogDir = path.join(process.cwd(), 'logs');
            }

            return path.join(baseLogDir, '%Y', '%m', 'app-%d.log');
        })(),
        logToConsole: process.env.LOG_TO_CONSOLE !== 'false',
        colorize: process.env.LOG_COLORIZE !== 'false',
        timestampFormat: process.env.LOG_TIMESTAMP_FORMAT || 'YYYY-MM-DD HH:mm:ss.SSS',
        maxFileSize: process.env.LOG_MAX_FILE_SIZE || '100m',
        maxFiles: parseInt(process.env.LOG_MAX_FILES, 10) || 14,
        maxAge: process.env.LOG_MAX_AGE || '30d',
        rotate: process.env.LOG_ROTATE !== 'false',
        json: process.env.LOG_JSON === 'true',
        prettyPrint: process.env.LOG_PRETTY_PRINT === 'true' || process.env.NODE_ENV !== 'production',
    },
    
    // Media Handling
    media: {
        maxSizeMB: parseInt(process.env.MAX_MEDIA_SIZE_MB, 10) || 16,
        downloadTimeoutMs: parseInt(process.env.MEDIA_DOWNLOAD_TIMEOUT_MS, 10) || 30000,
        allowedTypes: (process.env.ALLOWED_MEDIA_TYPES || 'image/jpeg,image/png,image/gif,video/mp4,audio/mpeg,audio/ogg,application/pdf').split(',').map(t => t.trim()),
        storagePath: process.env.MEDIA_STORAGE_PATH || path.join(process.cwd(), 'media'),
        maxStorageMB: parseInt(process.env.MAX_STORAGE_MB, 10) || 1024, // 1GB by default
    },
    
    // Security
    security: {
        rateLimitWindowMs: parseInt(process.env.RATE_LIMIT_WINDOW_MS, 10) || 900000, // 15 minutes
        rateLimitMaxRequests: parseInt(process.env.RATE_LIMIT_MAX_REQUESTS, 10) || 100,
        webhookSecret: process.env.WEBHOOK_SECRET || '',
        corsOrigins: (process.env.CORS_ORIGINS || '*').split(',').map(o => o.trim()),
        enableCors: process.env.ENABLE_CORS !== 'false',
        enableRateLimit: process.env.ENABLE_RATE_LIMIT !== 'false',
    },
    
    // Cache Configuration
    cache: {
        enabled: process.env.CACHE_ENABLED === 'true',
        ttl: parseInt(process.env.CACHE_TTL_MS, 10) || 3600000, // 1 hour
        maxItems: parseInt(process.env.CACHE_MAX_ITEMS, 10) || 1000,
    },
    
    // Metrics and Monitoring
    metrics: {
        enabled: process.env.METRICS_ENABLED === 'true',
        port: parseInt(process.env.METRICS_PORT, 10) || 9090,
        path: process.env.METRICS_PATH || '/metrics',
        collectDefaultMetrics: process.env.METRICS_COLLECT_DEFAULT === 'true',
    },
};

// Validate required configurations
const requiredConfigs = [
    { key: 'BACKEND_API_URL', value: config.backend.apiUrl },
    { key: 'NODE_ENV', value: config.nodeEnv, validate: (val) => ['development', 'production', 'test'].includes(val) },
];

const errors = [];

requiredConfigs.forEach(({ key, value, validate }) => {
    if (!value) {
        errors.push(`Missing required configuration: ${key}`);
    } else if (validate && !validate(value)) {
        errors.push(`Invalid value for ${key}: ${value}`);
    }
});

if (errors.length > 0) {
    // Use console.error since logger might not be initialized yet
    errors.forEach(error => console.error(`[CONFIG ERROR] ${error}`));
    process.exit(1);
}

// Ensure required directories exist
const requiredDirs = [
    config.whatsapp.authDir,
    path.dirname(config.logging.logFilePath),
    config.media.storagePath,
].filter(Boolean);

requiredDirs.forEach(dir => {
    try {
        if (dir && !fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }
    } catch (error) {
        console.error(`[CONFIG ERROR] Failed to create directory ${dir}:`, error.message);
        process.exit(1);
    }
});

export default config;
