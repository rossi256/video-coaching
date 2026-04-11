module.exports = {
  apps: [
    {
      name: 'wingcoach',
      script: 'server.js',
      cwd: '/home/ari/wingcoach-admin',
      instances: 1,
      autorestart: true,
      watch: false,
      max_memory_restart: '256M',
      env: {
        NODE_ENV: 'production',
        PORT: 3010,
        PUBLIC_PATH: '/projects/video-coaching',
        DEV_BYPASS: 'true',
      },
      error_file: '/home/ari/logs/wingcoach-error.log',
      out_file: '/home/ari/logs/wingcoach-out.log',
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
    },
  ],
};
