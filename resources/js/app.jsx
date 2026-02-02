import './bootstrap';
import '../css/app.css';
import React, { useEffect, useState } from 'react';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ThemeProvider } from './contexts/ThemeContext';
import { AuthProvider } from './contexts/AuthContext';
import { ToastProvider } from './contexts/ToastContext';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Helper function to refresh CSRF token
function refreshCsrfToken() {
    const token = document.head.querySelector('meta[name="csrf-token"]');
    if (token && window.axios) {
        window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
    }
}

// Refresh CSRF token after every Inertia navigation
// This ensures the token is always current after login/logout or page transitions
router.on('finish', () => {
    refreshCsrfToken();
});

/* Glob every page once, grouped by root ---------------------------- */
const adminPages  = import.meta.glob('./admin/Pages/**/*.jsx');
const publicPages = import.meta.glob('./public/Pages/**/*.jsx');
const parentPages = import.meta.glob('./parent/Pages/**/*.jsx');
const superadminPages = import.meta.glob('./superadmin/Pages/**/*.jsx');
const corePages   = import.meta.glob('./Pages/**/*.jsx');      // optional catch-all

createInertiaApp({
  title: title => `${title} - ${appName}`,

  /* Inertia hands us the page name exactly as you called it in PHP. */
  resolve: name => {
    if (name.startsWith('@admin/')) {
      const page = name.slice('@admin/'.length);                // "Articles/IndexArticle"
      return resolvePageComponent(`./admin/Pages/${page}.jsx`, adminPages);
    }

    if (name.startsWith('@public/')) {
      const page = name.slice('@public/'.length);
      return resolvePageComponent(`./public/Pages/${page}.jsx`, publicPages);
    }
     if (name.startsWith('@parent/')) {
      const page = name.slice('@parent/'.length);
      return resolvePageComponent(`./parent/Pages/${page}.jsx`, parentPages);
    }
     if (name.startsWith('@superadmin/')) {
      const page = name.slice('@superadmin/'.length);
      return resolvePageComponent(`./superadmin/Pages/${page}.jsx`, superadminPages);
    }

    // Anything without an alias goes to /pages by convention.
    return resolvePageComponent(`./Pages/${name}.jsx`, corePages);
  },

  setup({ el, App, props }) {
    const AppRoot = () => {
      const [branding, setBranding] = useState(props.initialPage.props.organizationBranding);
      const initialUser = props.initialPage.props.auth?.user || null;

      useEffect(() => {
        return router.on('navigate', (event) => {
          setBranding(event.detail.page.props?.organizationBranding);
        });
      }, []);

      return (
        <ThemeProvider organizationBranding={branding}>
          <AuthProvider initialUser={initialUser}>
            <ToastProvider>
              <App {...props} />
            </ToastProvider>
          </AuthProvider>
        </ThemeProvider>
      );
    };

    createRoot(el).render(<AppRoot />);
  },

  progress: {
    color: '#4B5563',          // nice Tailwind slate-600
  },
});
