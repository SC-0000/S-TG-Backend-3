// vite.config.js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  resolve: {
    alias: {
      react: path.resolve(__dirname, 'node_modules/react'),
      'react-dom': path.resolve(__dirname, 'node_modules/react-dom'),
       '@admin':  path.resolve(__dirname, 'resources/js/admin/pages'),
      '@public': path.resolve(__dirname, 'resources/js/public/pages'),
      '@parent': path.resolve(__dirname, 'resources/js/parent/pages'),
      '@superadmin': path.resolve(__dirname, 'resources/js/superadmin/pages'),
     
     
       
    },
  },
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app-api.jsx'],
      refresh: true,
    }),
    react(),
  ],
});
