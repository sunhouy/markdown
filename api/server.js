const express = require('express');
const cors = require('cors');
const path = require('path');
const fs = require('fs');

const app = express();
const port = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json({ limit: '50mb' }));
app.use(express.urlencoded({ extended: true, limit: '50mb' }));

// Static files for uploads
app.use('/uploads', express.static(path.join(__dirname, 'uploads')));
app.use('/avatars', express.static(path.join(__dirname, 'avatars')));
app.use('/screenshots', express.static(path.join(__dirname, 'screenshots')));

// Serve static files from node_modules for Vditor
app.use('/vditor', express.static(path.join(__dirname, '../node_modules/vditor/dist')));
// Serve Font Awesome from node_modules
app.use('/fa', express.static(path.join(__dirname, '../node_modules/@fortawesome/fontawesome-free')));

// Serve frontend static files
// If dist folder exists (production build), serve it
const distPath = path.join(__dirname, '../dist');
if (fs.existsSync(distPath)) {
    app.use(express.static(distPath));
} else {
    // Fallback to serving root for development (though Vite is recommended)
    // Note: Root index.html now uses modules, so it won't work directly without Vite
    app.use(express.static(path.join(__dirname, '../')));
}

// Import routes
// const legacyRoutes = require('./routes/legacy');
const authRoutes = require('./routes/auth');
const fileRoutes = require('./routes/files');
const shareRoutes = require('./routes/share');
const adminRoutes = require('./routes/admin');
const apiRoutes = require('./routes/api');

// Use routes
// app.use('/api', legacyRoutes);
app.use('/api/auth', authRoutes);
app.use('/api/files', fileRoutes);
app.use('/api/share', shareRoutes);
app.use('/api/admin', adminRoutes);
app.use('/api/external', apiRoutes);

// Error handling middleware
app.use((err, req, res, next) => {
    console.error(err.stack);
    res.status(500).json({
        code: 500,
        message: '服务器内部错误',
        error: err.message
    });
});

// Start server
if (require.main === module) {
    app.listen(port, () => {
        console.log(`Server is running on port ${port}`);
    });
}

module.exports = app;
