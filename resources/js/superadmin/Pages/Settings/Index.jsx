import React, { useEffect, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    Settings, Save, Globe, Mail, Shield, Bell, 
    Database, Palette, Code, Zap, AlertCircle 
} from 'lucide-react';
import { apiClient, extractValidationErrors } from '@/api';
import { useToast } from '@/contexts/ToastContext';

export default function SystemSettings() {
    const { showError, showSuccess } = useToast();
    const [activeTab, setActiveTab] = useState('general');
    const [loading, setLoading] = useState(true);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const [data, setData] = useState({
        // General Settings
        site_name: 'We work People',
        site_description: '',
        contact_email: '',
        support_phone: '',
        
        // Email Settings
        mail_driver: 'smtp',
        mail_host: '',
        mail_port: '587',
        mail_username: '',
        mail_password: '',
        mail_from_address: '',
        mail_from_name: '',
        
        // Feature Toggles
        enable_registrations: true,
        enable_guest_checkout: true,
        enable_ai_features: true,
        enable_live_lessons: true,
        enable_notifications: true,
        
        // System Settings
        maintenance_mode: false,
        cache_enabled: true,
        debug_mode: false,
        
        // Appearance
        primary_color: '#3B82F6',
        secondary_color: '#8B5CF6',
        logo_url: '',
    });

    useEffect(() => {
        let mounted = true;

        const loadSettings = async () => {
            try {
                setLoading(true);
                const response = await apiClient.get('/superadmin/system/settings', { useToken: true });
                if (!mounted) return;
                if (response?.data) {
                    setData((prev) => ({ ...prev, ...response.data }));
                }
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load settings.');
            } finally {
                if (mounted) setLoading(false);
            }
        };

        loadSettings();

        return () => {
            mounted = false;
        };
    }, [showError]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});
        try {
            await apiClient.post('/superadmin/system/settings', data, { useToken: true });
            showSuccess('Settings updated.');
        } catch (error) {
            const fieldErrors = extractValidationErrors(error);
            setErrors(fieldErrors);
            showError(error.message || 'Unable to save settings.');
        } finally {
            setProcessing(false);
        }
    };

    const tabs = [
        { id: 'general', name: 'General', icon: Globe },
        { id: 'email', name: 'Email', icon: Mail },
        { id: 'features', name: 'Features', icon: Zap },
        { id: 'system', name: 'System', icon: Database },
        { id: 'appearance', name: 'Appearance', icon: Palette },
    ];

    if (loading) {
        return (
            <SuperAdminLayout>
                <div className="text-gray-600">Loading settings...</div>
            </SuperAdminLayout>
        );
    }

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                {/* Header */}
                <div>
                    <h1 className="text-3xl font-bold text-gray-900">System Settings</h1>
                    <p className="text-gray-500 mt-1">Manage platform configuration and preferences</p>
                </div>

                {/* Tabs */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200">
                    <div className="border-b border-gray-200">
                        <nav className="flex space-x-8 px-6" aria-label="Tabs">
                            {tabs.map((tab) => {
                                const Icon = tab.icon;
                                return (
                                    <button
                                        key={tab.id}
                                        onClick={() => setActiveTab(tab.id)}
                                        className={`flex items-center gap-2 py-4 px-1 border-b-2 font-medium text-sm transition ${
                                            activeTab === tab.id
                                                ? 'border-blue-500 text-blue-600'
                                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                        }`}
                                    >
                                        <Icon className="h-5 w-5" />
                                        {tab.name}
                                    </button>
                                );
                            })}
                        </nav>
                    </div>

                    <form onSubmit={handleSubmit} className="p-6">
                        {/* General Settings */}
                        {activeTab === 'general' && (
                            <div className="space-y-6">
                                <div>
                                    <h3 className="text-lg font-bold text-gray-900 mb-4">General Settings</h3>
                                    <p className="text-sm text-gray-600 mb-6">Configure basic platform information</p>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Site Name
                                        </label>
                                        <input
                                            type="text"
                                            value={data.site_name}
                                            onChange={e => setData((prev) => ({ ...prev, site_name: e.target.value }))}
                                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Contact Email
                                        </label>
                                        <input
                                            type="email"
                                            value={data.contact_email}
                                            onChange={e => setData((prev) => ({ ...prev, contact_email: e.target.value }))}
                                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        />
                                    </div>

                                    <div className="md:col-span-2">
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Site Description
                                        </label>
                                        <textarea
                                            value={data.site_description}
                                            onChange={e => setData((prev) => ({ ...prev, site_description: e.target.value }))}
                                            rows="3"
                                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Support Phone
                                        </label>
                                        <input
                                            type="tel"
                                            value={data.support_phone}
                                            onChange={e => setData((prev) => ({ ...prev, support_phone: e.target.value }))}
                                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        />
                                    </div>
                                </div>
                            </div>
                        )}

                        {/* Email Settings */}
                        {activeTab === 'email' && (
                            <div className="space-y-6">
                                <div>
                                    <h3 className="text-lg font-bold text-gray-900 mb-4">Email Configuration</h3>
                                    <p className="text-sm text-gray-600 mb-6">Configure SMTP settings for sending emails</p>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    {[
                                        { key: 'mail_driver', label: 'Mail Driver' },
                                        { key: 'mail_host', label: 'Mail Host' },
                                        { key: 'mail_port', label: 'Mail Port' },
                                        { key: 'mail_username', label: 'Mail Username' },
                                        { key: 'mail_password', label: 'Mail Password', type: 'password' },
                                        { key: 'mail_from_address', label: 'From Address' },
                                        { key: 'mail_from_name', label: 'From Name' },
                                    ].map((field) => (
                                        <div key={field.key}>
                                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                                {field.label}
                                            </label>
                                            <input
                                                type={field.type || 'text'}
                                                value={data[field.key]}
                                                onChange={(e) => setData((prev) => ({ ...prev, [field.key]: e.target.value }))}
                                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                            />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Features */}
                        {activeTab === 'features' && (
                            <div className="space-y-6">
                                <div>
                                    <h3 className="text-lg font-bold text-gray-900 mb-4">Feature Toggles</h3>
                                    <p className="text-sm text-gray-600 mb-6">Enable or disable platform features</p>
                                </div>
                                <div className="space-y-4">
                                    {[
                                        { key: 'enable_registrations', label: 'Enable Registrations' },
                                        { key: 'enable_guest_checkout', label: 'Enable Guest Checkout' },
                                        { key: 'enable_ai_features', label: 'Enable AI Features' },
                                        { key: 'enable_live_lessons', label: 'Enable Live Lessons' },
                                        { key: 'enable_notifications', label: 'Enable Notifications' },
                                    ].map((field) => (
                                        <label key={field.key} className="flex items-center gap-3">
                                            <input
                                                type="checkbox"
                                                checked={!!data[field.key]}
                                                onChange={(e) => setData((prev) => ({ ...prev, [field.key]: e.target.checked }))}
                                            />
                                            <span className="text-gray-700">{field.label}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* System */}
                        {activeTab === 'system' && (
                            <div className="space-y-6">
                                <div>
                                    <h3 className="text-lg font-bold text-gray-900 mb-4">System Settings</h3>
                                    <p className="text-sm text-gray-600 mb-6">Maintenance and debugging options</p>
                                </div>
                                <div className="space-y-4">
                                    {[
                                        { key: 'maintenance_mode', label: 'Maintenance Mode' },
                                        { key: 'cache_enabled', label: 'Cache Enabled' },
                                        { key: 'debug_mode', label: 'Debug Mode' },
                                    ].map((field) => (
                                        <label key={field.key} className="flex items-center gap-3">
                                            <input
                                                type="checkbox"
                                                checked={!!data[field.key]}
                                                onChange={(e) => setData((prev) => ({ ...prev, [field.key]: e.target.checked }))}
                                            />
                                            <span className="text-gray-700">{field.label}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Appearance */}
                        {activeTab === 'appearance' && (
                            <div className="space-y-6">
                                <div>
                                    <h3 className="text-lg font-bold text-gray-900 mb-4">Appearance</h3>
                                    <p className="text-sm text-gray-600 mb-6">Branding and UI configuration</p>
                                </div>
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Primary Color
                                        </label>
                                        <input
                                            type="color"
                                            value={data.primary_color}
                                            onChange={(e) => setData((prev) => ({ ...prev, primary_color: e.target.value }))}
                                            className="w-full h-10"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Secondary Color
                                        </label>
                                        <input
                                            type="color"
                                            value={data.secondary_color}
                                            onChange={(e) => setData((prev) => ({ ...prev, secondary_color: e.target.value }))}
                                            className="w-full h-10"
                                        />
                                    </div>
                                    <div className="md:col-span-2">
                                        <label className="block text-sm font-medium text-gray-700 mb-2">
                                            Logo URL
                                        </label>
                                        <input
                                            type="text"
                                            value={data.logo_url}
                                            onChange={(e) => setData((prev) => ({ ...prev, logo_url: e.target.value }))}
                                            className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        />
                                    </div>
                                </div>
                            </div>
                        )}

                        <div className="mt-8 flex items-center gap-4">
                            <button
                                type="submit"
                                disabled={processing}
                                className="flex items-center gap-2 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition disabled:opacity-50"
                            >
                                <Save className="h-4 w-4" />
                                {processing ? 'Saving...' : 'Save Changes'}
                            </button>
                            {Object.keys(errors).length > 0 && (
                                <div className="flex items-center gap-2 text-red-600 text-sm">
                                    <AlertCircle className="h-4 w-4" />
                                    Please fix the highlighted fields.
                                </div>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
