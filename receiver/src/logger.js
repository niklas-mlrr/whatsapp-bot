const pino = require('pino');
const config = require('./config');
const path = require('path');
const fs = require('fs');

// Ensure log directory exists
const ensureLogDirectoryExists = (filePath) => {
    const logDir = path.dirname(filePath);
    if (!fs.existsSync(logDir)) {
        fs.mkdirSync(logDir, { recursive: true });
    }
};

// Create a base logger configuration
const createBaseLogger = () => {
    const isDevelopment = config.nodeEnv === 'development';
    
    // Common logger options
    const options = {
        level: config.logging.level,
        timestamp: () => `,"time":"${new Date().toISOString()}"`,
        formatters: {
            level: (label) => ({ level: label.toUpperCase() }),
        },
        serializers: {
            error: pino.stdSerializers.err,
            req: pino.stdSerializers.req,
            res: pino.stdSerializers.res,
        },
    };

    // Add pretty printing in development
    if (isDevelopment) {
        options.transport = {
            target: 'pino-pretty',
            options: {
                colorize: true,
                translateTime: 'SYS:standard',
                ignore: 'pid,hostname',
            },
        };
    }

    return pino(options);
};

// Create the main logger
let logger = createBaseLogger();

// Add file transport if enabled
if (config.logging.logToFile) {
    try {
        ensureLogDirectoryExists(config.logging.logFilePath);
        
        // Determine rotation availability
        let rotationEnabled = config.logging.rotate;
        if (rotationEnabled) {
            try {
                require.resolve('pino-roll');
            } catch (rotationError) {
                rotationEnabled = false;
                console.warn('[LOGGER] pino-roll module not found. Disabling log rotation.');
            }
        }

        // Create a file transport with rotation when available
        const transportTargets = [
            {
                level: config.logging.level,
                target: 'pino/file',
                options: {
                    destination: config.logging.logFilePath,
                    mkdir: true,
                },
            },
        ];

        if (rotationEnabled) {
            // Extract directory and filename for rotation
            const logDir = path.dirname(config.logging.logFilePath);
            const logFileName = path.basename(config.logging.logFilePath, '.log');
            
            transportTargets.push({
                level: config.logging.level,
                target: 'pino-roll',
                options: {
                    file: path.join(logDir, `${logFileName}-%Y-%m-%d.log`),
                    frequency: 'daily',
                    mkdir: true,
                    size: config.logging.maxFileSize || '100m',
                    age: config.logging.maxAge || '30d',
                    count: config.logging.maxFiles || 10,
                },
            });
        }

        const fileTransport = pino.transport({
            targets: transportTargets,
        });
        
        // Create a new logger with both console and file transports
        logger = pino(
            {
                level: config.logging.level,
                timestamp: pino.stdTimeFunctions.isoTime,
            },
            pino.multistream([
                { stream: process.stdout },
                { stream: fileTransport },
            ])
        );
        
        logger.info(`Logging to file: ${path.resolve(config.logging.logFilePath)}`);
    } catch (error) {
        // If file logging fails, fall back to console logging
        logger.error({ error }, 'Failed to initialize file logging. Falling back to console logging.');
    }
}

// Add request/response logging middleware
const requestLogger = (req, res, next) => {
    const start = Date.now();
    
    res.on('finish', () => {
        const responseTime = Date.now() - start;
        
        logger.info({
            method: req.method,
            url: req.url,
            status: res.statusCode,
            responseTime: `${responseTime}ms`,
            ip: req.ip || req.connection.remoteAddress,
            userAgent: req.headers['user-agent'],
        }, 'Request completed');
    });
    
    next();
};

// Add uncaught exception handler
process.on('uncaughtException', (error) => {
    logger.fatal({
        error: error.message,
        stack: error.stack,
        process: {
            pid: process.pid,
            uid: process.getuid ? process.getuid() : null,
            gid: process.getgid ? process.getgid() : null,
            version: process.version,
            memoryUsage: process.memoryUsage(),
        },
    }, 'Uncaught Exception');
    
    // In production, consider whether to exit or keep the process alive
    if (process.env.NODE_ENV === 'production') {
        process.exit(1);
    }
});

// Add unhandled promise rejection handler
process.on('unhandledRejection', (reason, promise) => {
    logger.error({
        error: reason instanceof Error ? reason.message : reason,
        stack: reason instanceof Error ? reason.stack : undefined,
        promise: {
            // Remove non-standard promise inspection methods
            // that are not available in native Promises
        },
    }, 'Unhandled Promise Rejection');
});

// Add process exit handler
process.on('exit', (code) => {
    logger.info(`Process exiting with code ${code}`);
});

// Add signal handlers
['SIGINT', 'SIGTERM', 'SIGHUP'].forEach((signal) => {
    process.on(signal, () => {
        logger.info(`Received ${signal}. Gracefully shutting down...`);
        // Perform any cleanup here
        process.exit(0);
    });
});

module.exports = {
    logger,
    requestLogger,
    createChildLogger: (name) => logger.child({ module: name }),
};
