import React, { useEffect, useState } from 'react';
import { 
    Menu, X, LayoutDashboard, Users, Building2, FileText, 
    Settings, DollarSign, BarChart3, FileSearch, ChevronDown, 
    Shield, LogOut, Briefcase 
} from 'lucide-react';
import EptLogo from '@/admin/assets/EPT Logo web.png';
import { apiClient } from '@/api';
import { clearAuthToken, getCurrentUser, setCurrentUser } from '@/stores/authStore';
import { useToast } from '@/contexts/ToastContext';
import { useAuth } from '@/contexts/AuthContext';

const navigationSections = [
    {
        label: 'Overview',
        items: [
            { key: 'dashboard', label: 'Dashboard', href: '/superadmin/dashboard', icon: LayoutDashboard },
        ]
    },
    {
        label: 'Management',
        items: [
            { key: 'site-admin', label: 'Site Administration', href: '/superadmin/site-admin', icon: Briefcase },
            { key: 'users', label: 'User Management', href: '/superadmin/users', icon: Users },
            { key: 'organizations', label: 'Organizations', href: '/superadmin/organizations', icon: Building2 },
            { key: 'content', label: 'Content Management', href: '/superadmin/content/courses', icon: FileText },
        ]
    },
    {
        label: 'Operations',
        items: [
            { key: 'billing', label: 'Billing', href: '/superadmin/billing/overview', icon: DollarSign },
            { key: 'analytics', label: 'Analytics', href: '/superadmin/analytics', icon: BarChart3 },
            { key: 'logs', label: 'Logs & Monitoring', href: '/superadmin/logs', icon: FileSearch },
        ]
    },
    {
        label: 'Configuration',
        items: [
            { key: 'system', label: 'System Settings', href: '/superadmin/system/settings', icon: Settings },
        ]
    }
];

export default function SuperAdminLayout({ children }) {
    const { isAuthenticated, loading, initialized } = useAuth();

    useEffect(() => {
        if (!initialized || loading || isAuthenticated) return;

        const target = import.meta.env.VITE_AUTH_LOGIN_URL || '/authenticate-user';
        const loginUrl = /^https?:\/\//i.test(target)
            ? target
            : (target.startsWith('/') ? target : `/${target}`);

        if (typeof window !== 'undefined' && window.location.href !== loginUrl) {
            window.location.href = loginUrl;
        }
    }, [initialized, loading, isAuthenticated]);

    const { showError } = useToast();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [user, setUser] = useState(getCurrentUser());
    const userName = user?.name || 'Super Admin';
    const currentPath = window.location.pathname;

    const isActive = (href) => currentPath.startsWith(href);

    useEffect(() => {
        let mounted = true;

        const loadUser = async () => {
            if (getCurrentUser()) return;
            try {
                const response = await apiClient.get('/auth/me', { useToken: true });
                if (!mounted) return;
                setCurrentUser(response?.data?.user || response?.data || null);
                setUser(response?.data?.user || response?.data || null);
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load user.');
            }
        };

        loadUser();

        return () => {
            mounted = false;
        };
    }, [showError]);

    const handleLogout = async () => {
        try {
            await apiClient.post('/auth/logout', null, { useToken: true });
        } catch (error) {
            // ignore network failures; still clear token
        } finally {
            clearAuthToken();
            window.location.href = '/login';
        }
    };

    return (
        <div className="min-h-screen bg-gray-50">
            {/* Mobile Sidebar Overlay */}
            {sidebarOpen && (
                <div 
                    className="fixed inset-0 bg-black/50 z-40 lg:hidden"
                    onClick={() => setSidebarOpen(false)}
                />
            )}

            {/* Sidebar */}
            <aside className={`
                fixed top-0 left-0 h-full w-64 bg-gradient-to-b from-slate-900 to-slate-800 
                shadow-2xl z-50 transform transition-transform duration-300 ease-in-out
                ${sidebarOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0
            `}>
                {/* Logo */}
                <div className="flex items-center justify-between p-6 border-b border-slate-700">
                    <div className="flex items-center space-x-3">
                        <Shield className="h-8 w-8 text-blue-400" />
                        <div className="flex flex-col">
                            <span className="text-white font-bold text-lg">Super Admin</span>
                            <span className="text-xs text-slate-400">Control Center</span>
                        </div>
                    </div>
                    <button 
                        onClick={() => setSidebarOpen(false)}
                        className="lg:hidden text-slate-400 hover:text-white"
                    >
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {/* Navigation */}
                <nav className="p-4 space-y-6 overflow-y-auto h-[calc(100vh-180px)]">
                    {navigationSections.map((section) => (
                        <div key={section.label}>
                            <h3 className="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-3">
                                {section.label}
                            </h3>
                            <div className="space-y-1">
                                {section.items.map((item) => {
                                    const Icon = item.icon;
                                    const active = isActive(item.href);
                                    return (
                                        <a
                                            key={item.key}
                                            href={item.href}
                                            className={`
                                                flex items-center gap-3 px-4 py-3 rounded-lg transition-all
                                                ${active 
                                                    ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/50' 
                                                    : 'text-slate-300 hover:bg-slate-700/50 hover:text-white'
                                                }
                                            `}
                                            onClick={() => setSidebarOpen(false)}
                                        >
                                            <Icon className="h-5 w-5" />
                                            <span className="font-medium text-sm">{item.label}</span>
                                        </a>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </nav>

                {/* User Section */}
                <div className="absolute bottom-0 left-0 right-0 p-4 border-t border-slate-700 bg-slate-900/50">
                    <div className="flex items-center gap-3 px-4 py-3 rounded-lg bg-slate-800">
                        <div className="flex-shrink-0 h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold">
                            {userName.charAt(0)}
                        </div>
                        <div className="flex-1 min-w-0">
                            <p className="text-sm font-medium text-white truncate">{userName}</p>
                            <p className="text-xs text-slate-400">Super Administrator</p>
                        </div>
                    </div>
                </div>
            </aside>

            {/* Main Content */}
            <div className="lg:ml-64">
                {/* Top Header */}
                <header className="sticky top-0 z-30 bg-white border-b border-gray-200 shadow-sm">
                    <div className="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-4">
                        <div className="flex items-center gap-4">
                            <button
                                onClick={() => setSidebarOpen(true)}
                                className="lg:hidden p-2 rounded-lg hover:bg-gray-100"
                            >
                                <Menu className="h-6 w-6 text-gray-600" />
                            </button>
                            <img src={EptLogo} alt="EPT Logo" className="h-8 w-auto" />
                        </div>
                        
                        <div className="flex items-center gap-4">
                            <button
                                type="button"
                                onClick={handleLogout}
                                className="flex items-center gap-2 px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 rounded-lg hover:bg-gray-100 transition"
                            >
                                <LogOut className="h-4 w-4" />
                                <span className="hidden sm:inline">Logout</span>
                            </button>
                        </div>
                    </div>
                </header>

                {/* Page Content */}
                <main className="p-4 sm:p-6 lg:p-8">
                    {children}
                </main>
            </div>
        </div>
    );
}
