import React, { createContext, useContext, useEffect } from 'react';

const ThemeContext = createContext(null);

export const useTheme = () => {
    const context = useContext(ThemeContext);
    if (!context) {
        throw new Error('useTheme must be used within a ThemeProvider');
    }
    return context;
};

const hexToRgb = (hex) => {
    const h = hex?.replace('#', '') || '000000';
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    return `${r} ${g} ${b}`;
};

const coalesce = (value, fallback) =>
    value !== undefined && value !== null ? value : fallback;

export const ThemeProvider = ({ children, organizationBranding }) => {
    useEffect(() => {
        console.log('🎨 ThemeProvider received organizationBranding:', organizationBranding);
    }, [organizationBranding]);

    const theme = {
        colors: {
            // Primary palette
            primary: coalesce(organizationBranding?.colors?.primary, '#411183'),
            primary50: coalesce(organizationBranding?.colors?.primary_50, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary100: coalesce(organizationBranding?.colors?.primary_100, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary200: coalesce(organizationBranding?.colors?.primary_200, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary300: coalesce(organizationBranding?.colors?.primary_300, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary400: coalesce(organizationBranding?.colors?.primary_400, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary500: coalesce(organizationBranding?.colors?.primary_500, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary600: coalesce(organizationBranding?.colors?.primary_600, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary700: coalesce(organizationBranding?.colors?.primary_700, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary800: coalesce(organizationBranding?.colors?.primary_800, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary900: coalesce(organizationBranding?.colors?.primary_900, coalesce(organizationBranding?.colors?.primary, '#411183')),
            primary950: coalesce(organizationBranding?.colors?.primary_950, coalesce(organizationBranding?.colors?.primary, '#411183')),

            // Accent palette
            accent: coalesce(organizationBranding?.colors?.accent, '#1F6DF2'),
            accent50: coalesce(organizationBranding?.colors?.accent_50, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent100: coalesce(organizationBranding?.colors?.accent_100, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent200: coalesce(organizationBranding?.colors?.accent_200, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent300: coalesce(organizationBranding?.colors?.accent_300, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent400: coalesce(organizationBranding?.colors?.accent_400, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent500: coalesce(organizationBranding?.colors?.accent_500, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent600: coalesce(organizationBranding?.colors?.accent_600, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent700: coalesce(organizationBranding?.colors?.accent_700, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent800: coalesce(organizationBranding?.colors?.accent_800, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent900: coalesce(organizationBranding?.colors?.accent_900, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),
            accent950: coalesce(organizationBranding?.colors?.accent_950, coalesce(organizationBranding?.colors?.accent, '#1F6DF2')),

            // Accent soft palette
            accentSoft: coalesce(organizationBranding?.colors?.accent_soft, '#f77052'),
            accentSoft50: coalesce(organizationBranding?.colors?.accent_soft_50, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft100: coalesce(organizationBranding?.colors?.accent_soft_100, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft200: coalesce(organizationBranding?.colors?.accent_soft_200, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft300: coalesce(organizationBranding?.colors?.accent_soft_300, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft400: coalesce(organizationBranding?.colors?.accent_soft_400, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft500: coalesce(organizationBranding?.colors?.accent_soft_500, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft600: coalesce(organizationBranding?.colors?.accent_soft_600, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft700: coalesce(organizationBranding?.colors?.accent_soft_700, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft800: coalesce(organizationBranding?.colors?.accent_soft_800, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft900: coalesce(organizationBranding?.colors?.accent_soft_900, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),
            accentSoft950: coalesce(organizationBranding?.colors?.accent_soft_950, coalesce(organizationBranding?.colors?.accent_soft, '#f77052')),

            // Secondary palette
            secondary: coalesce(organizationBranding?.colors?.secondary, '#B4C8E8'),
            secondary50: coalesce(organizationBranding?.colors?.secondary_50, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary100: coalesce(organizationBranding?.colors?.secondary_100, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary200: coalesce(organizationBranding?.colors?.secondary_200, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary300: coalesce(organizationBranding?.colors?.secondary_300, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary400: coalesce(organizationBranding?.colors?.secondary_400, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary500: coalesce(organizationBranding?.colors?.secondary_500, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary600: coalesce(organizationBranding?.colors?.secondary_600, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary700: coalesce(organizationBranding?.colors?.secondary_700, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary800: coalesce(organizationBranding?.colors?.secondary_800, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary900: coalesce(organizationBranding?.colors?.secondary_900, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),
            secondary950: coalesce(organizationBranding?.colors?.secondary_950, coalesce(organizationBranding?.colors?.secondary, '#B4C8E8')),

            // Heavy palette
            heavy: coalesce(organizationBranding?.colors?.heavy, '#1F6DF2'),
            heavy50: coalesce(organizationBranding?.colors?.heavy_50, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy100: coalesce(organizationBranding?.colors?.heavy_100, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy200: coalesce(organizationBranding?.colors?.heavy_200, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy300: coalesce(organizationBranding?.colors?.heavy_300, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy400: coalesce(organizationBranding?.colors?.heavy_400, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy500: coalesce(organizationBranding?.colors?.heavy_500, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy600: coalesce(organizationBranding?.colors?.heavy_600, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy700: coalesce(organizationBranding?.colors?.heavy_700, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy800: coalesce(organizationBranding?.colors?.heavy_800, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy900: coalesce(organizationBranding?.colors?.heavy_900, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
            heavy950: coalesce(organizationBranding?.colors?.heavy_950, coalesce(organizationBranding?.colors?.heavy, '#1F6DF2')),
        },
        branding: {
            logoUrl: organizationBranding?.logo_url,
            logoDarkUrl: organizationBranding?.logo_dark_url,
            faviconUrl: organizationBranding?.favicon_url,
            organizationName: organizationBranding?.name || 'We Work People',
            tagline: organizationBranding?.tagline,
            description: organizationBranding?.description,
            contact: organizationBranding?.contact || null,
            social: organizationBranding?.social || null,
        },
        customCSS: organizationBranding?.custom_css || '',
    };

    useEffect(() => {
        if (typeof document === 'undefined') return;
        const root = document.documentElement;

        // hex vars
        root.style.setProperty('--color-primary', theme.colors.primary);
        root.style.setProperty('--color-primary-50', theme.colors.primary50);
        root.style.setProperty('--color-primary-100', theme.colors.primary100);
        root.style.setProperty('--color-primary-200', theme.colors.primary200);
        root.style.setProperty('--color-primary-300', theme.colors.primary300);
        root.style.setProperty('--color-primary-400', theme.colors.primary400);
        root.style.setProperty('--color-primary-500', theme.colors.primary500);
        root.style.setProperty('--color-primary-600', theme.colors.primary600);
        root.style.setProperty('--color-primary-700', theme.colors.primary700);
        root.style.setProperty('--color-primary-800', theme.colors.primary800);
        root.style.setProperty('--color-primary-900', theme.colors.primary900);
        root.style.setProperty('--color-primary-950', theme.colors.primary950);

        root.style.setProperty('--color-accent', theme.colors.accent);
        root.style.setProperty('--color-accent-50', theme.colors.accent50);
        root.style.setProperty('--color-accent-100', theme.colors.accent100);
        root.style.setProperty('--color-accent-200', theme.colors.accent200);
        root.style.setProperty('--color-accent-300', theme.colors.accent300);
        root.style.setProperty('--color-accent-400', theme.colors.accent400);
        root.style.setProperty('--color-accent-500', theme.colors.accent500);
        root.style.setProperty('--color-accent-600', theme.colors.accent600);
        root.style.setProperty('--color-accent-700', theme.colors.accent700);
        root.style.setProperty('--color-accent-800', theme.colors.accent800);
        root.style.setProperty('--color-accent-900', theme.colors.accent900);
        root.style.setProperty('--color-accent-950', theme.colors.accent950);

        root.style.setProperty('--color-accent-soft', theme.colors.accentSoft);
        root.style.setProperty('--color-accent-soft-50', theme.colors.accentSoft50);
        root.style.setProperty('--color-accent-soft-100', theme.colors.accentSoft100);
        root.style.setProperty('--color-accent-soft-200', theme.colors.accentSoft200);
        root.style.setProperty('--color-accent-soft-300', theme.colors.accentSoft300);
        root.style.setProperty('--color-accent-soft-400', theme.colors.accentSoft400);
        root.style.setProperty('--color-accent-soft-500', theme.colors.accentSoft500);
        root.style.setProperty('--color-accent-soft-600', theme.colors.accentSoft600);
        root.style.setProperty('--color-accent-soft-700', theme.colors.accentSoft700);
        root.style.setProperty('--color-accent-soft-800', theme.colors.accentSoft800);
        root.style.setProperty('--color-accent-soft-900', theme.colors.accentSoft900);
        root.style.setProperty('--color-accent-soft-950', theme.colors.accentSoft950);

        root.style.setProperty('--color-secondary', theme.colors.secondary);
        root.style.setProperty('--color-secondary-50', theme.colors.secondary50);
        root.style.setProperty('--color-secondary-100', theme.colors.secondary100);
        root.style.setProperty('--color-secondary-200', theme.colors.secondary200);
        root.style.setProperty('--color-secondary-300', theme.colors.secondary300);
        root.style.setProperty('--color-secondary-400', theme.colors.secondary400);
        root.style.setProperty('--color-secondary-500', theme.colors.secondary500);
        root.style.setProperty('--color-secondary-600', theme.colors.secondary600);
        root.style.setProperty('--color-secondary-700', theme.colors.secondary700);
        root.style.setProperty('--color-secondary-800', theme.colors.secondary800);
        root.style.setProperty('--color-secondary-900', theme.colors.secondary900);
        root.style.setProperty('--color-secondary-950', theme.colors.secondary950);

        root.style.setProperty('--color-heavy', theme.colors.heavy);
        root.style.setProperty('--color-heavy-50', theme.colors.heavy50);
        root.style.setProperty('--color-heavy-100', theme.colors.heavy100);
        root.style.setProperty('--color-heavy-200', theme.colors.heavy200);
        root.style.setProperty('--color-heavy-300', theme.colors.heavy300);
        root.style.setProperty('--color-heavy-400', theme.colors.heavy400);
        root.style.setProperty('--color-heavy-500', theme.colors.heavy500);
        root.style.setProperty('--color-heavy-600', theme.colors.heavy600);
        root.style.setProperty('--color-heavy-700', theme.colors.heavy700);
        root.style.setProperty('--color-heavy-800', theme.colors.heavy800);
        root.style.setProperty('--color-heavy-900', theme.colors.heavy900);
        root.style.setProperty('--color-heavy-950', theme.colors.heavy950);

        // RGB vars for /opacity utilities
        root.style.setProperty('--color-primary-rgb', hexToRgb(theme.colors.primary));
        root.style.setProperty('--color-primary-50-rgb', hexToRgb(theme.colors.primary50));
        root.style.setProperty('--color-primary-100-rgb', hexToRgb(theme.colors.primary100));
        root.style.setProperty('--color-primary-200-rgb', hexToRgb(theme.colors.primary200));
        root.style.setProperty('--color-primary-300-rgb', hexToRgb(theme.colors.primary300));
        root.style.setProperty('--color-primary-400-rgb', hexToRgb(theme.colors.primary400));
        root.style.setProperty('--color-primary-500-rgb', hexToRgb(theme.colors.primary500));
        root.style.setProperty('--color-primary-600-rgb', hexToRgb(theme.colors.primary600));
        root.style.setProperty('--color-primary-700-rgb', hexToRgb(theme.colors.primary700));
        root.style.setProperty('--color-primary-800-rgb', hexToRgb(theme.colors.primary800));
        root.style.setProperty('--color-primary-900-rgb', hexToRgb(theme.colors.primary900));
        root.style.setProperty('--color-primary-950-rgb', hexToRgb(theme.colors.primary950));

        root.style.setProperty('--color-accent-rgb', hexToRgb(theme.colors.accent));
        root.style.setProperty('--color-accent-50-rgb', hexToRgb(theme.colors.accent50));
        root.style.setProperty('--color-accent-100-rgb', hexToRgb(theme.colors.accent100));
        root.style.setProperty('--color-accent-200-rgb', hexToRgb(theme.colors.accent200));
        root.style.setProperty('--color-accent-300-rgb', hexToRgb(theme.colors.accent300));
        root.style.setProperty('--color-accent-400-rgb', hexToRgb(theme.colors.accent400));
        root.style.setProperty('--color-accent-500-rgb', hexToRgb(theme.colors.accent500));
        root.style.setProperty('--color-accent-600-rgb', hexToRgb(theme.colors.accent600));
        root.style.setProperty('--color-accent-700-rgb', hexToRgb(theme.colors.accent700));
        root.style.setProperty('--color-accent-800-rgb', hexToRgb(theme.colors.accent800));
        root.style.setProperty('--color-accent-900-rgb', hexToRgb(theme.colors.accent900));
        root.style.setProperty('--color-accent-950-rgb', hexToRgb(theme.colors.accent950));

        root.style.setProperty('--color-accent-soft-rgb', hexToRgb(theme.colors.accentSoft));
        root.style.setProperty('--color-accent-soft-50-rgb', hexToRgb(theme.colors.accentSoft50));
        root.style.setProperty('--color-accent-soft-100-rgb', hexToRgb(theme.colors.accentSoft100));
        root.style.setProperty('--color-accent-soft-200-rgb', hexToRgb(theme.colors.accentSoft200));
        root.style.setProperty('--color-accent-soft-300-rgb', hexToRgb(theme.colors.accentSoft300));
        root.style.setProperty('--color-accent-soft-400-rgb', hexToRgb(theme.colors.accentSoft400));
        root.style.setProperty('--color-accent-soft-500-rgb', hexToRgb(theme.colors.accentSoft500));
        root.style.setProperty('--color-accent-soft-600-rgb', hexToRgb(theme.colors.accentSoft600));
        root.style.setProperty('--color-accent-soft-700-rgb', hexToRgb(theme.colors.accentSoft700));
        root.style.setProperty('--color-accent-soft-800-rgb', hexToRgb(theme.colors.accentSoft800));
        root.style.setProperty('--color-accent-soft-900-rgb', hexToRgb(theme.colors.accentSoft900));
        root.style.setProperty('--color-accent-soft-950-rgb', hexToRgb(theme.colors.accentSoft950));

        root.style.setProperty('--color-secondary-rgb', hexToRgb(theme.colors.secondary));
        root.style.setProperty('--color-secondary-50-rgb', hexToRgb(theme.colors.secondary50));
        root.style.setProperty('--color-secondary-100-rgb', hexToRgb(theme.colors.secondary100));
        root.style.setProperty('--color-secondary-200-rgb', hexToRgb(theme.colors.secondary200));
        root.style.setProperty('--color-secondary-300-rgb', hexToRgb(theme.colors.secondary300));
        root.style.setProperty('--color-secondary-400-rgb', hexToRgb(theme.colors.secondary400));
        root.style.setProperty('--color-secondary-500-rgb', hexToRgb(theme.colors.secondary500));
        root.style.setProperty('--color-secondary-600-rgb', hexToRgb(theme.colors.secondary600));
        root.style.setProperty('--color-secondary-700-rgb', hexToRgb(theme.colors.secondary700));
        root.style.setProperty('--color-secondary-800-rgb', hexToRgb(theme.colors.secondary800));
        root.style.setProperty('--color-secondary-900-rgb', hexToRgb(theme.colors.secondary900));
        root.style.setProperty('--color-secondary-950-rgb', hexToRgb(theme.colors.secondary950));

        root.style.setProperty('--color-heavy-rgb', hexToRgb(theme.colors.heavy));
        root.style.setProperty('--color-heavy-50-rgb', hexToRgb(theme.colors.heavy50));
        root.style.setProperty('--color-heavy-100-rgb', hexToRgb(theme.colors.heavy100));
        root.style.setProperty('--color-heavy-200-rgb', hexToRgb(theme.colors.heavy200));
        root.style.setProperty('--color-heavy-300-rgb', hexToRgb(theme.colors.heavy300));
        root.style.setProperty('--color-heavy-400-rgb', hexToRgb(theme.colors.heavy400));
        root.style.setProperty('--color-heavy-500-rgb', hexToRgb(theme.colors.heavy500));
        root.style.setProperty('--color-heavy-600-rgb', hexToRgb(theme.colors.heavy600));
        root.style.setProperty('--color-heavy-700-rgb', hexToRgb(theme.colors.heavy700));
        root.style.setProperty('--color-heavy-800-rgb', hexToRgb(theme.colors.heavy800));
        root.style.setProperty('--color-heavy-900-rgb', hexToRgb(theme.colors.heavy900));
        root.style.setProperty('--color-heavy-950-rgb', hexToRgb(theme.colors.heavy950));

        if (theme.customCSS) {
            let styleElement = document.getElementById('organization-custom-css');
            if (!styleElement) {
                styleElement = document.createElement('style');
                styleElement.id = 'organization-custom-css';
                document.head.appendChild(styleElement);
            }
            styleElement.textContent = theme.customCSS;
        }

        if (theme.branding.faviconUrl) {
            let favicon = document.querySelector('link[rel="icon"]');
            if (!favicon) {
                favicon = document.createElement('link');
                favicon.rel = 'icon';
                document.head.appendChild(favicon);
            }
            favicon.href = theme.branding.faviconUrl;
        }

        if (theme.branding.organizationName) {
            const currentTitle = document.title;
            if (!currentTitle.includes(theme.branding.organizationName)) {
                document.title = `${theme.branding.organizationName}`;
            }
        }

        return () => {
            const styleElement = document.getElementById('organization-custom-css');
            if (styleElement) styleElement.remove();
        };
    }, [theme]);

    return <ThemeContext.Provider value={theme}>{children}</ThemeContext.Provider>;
};

export default ThemeProvider;
