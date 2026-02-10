import React, { useEffect, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { Upload, X, Eye, Check, Palette as PaletteIcon, Mail, Globe, Settings } from 'lucide-react';
import { apiClient, extractValidationErrors } from '@/api';
import { useToast } from '@/contexts/ToastContext';

const COLOR_PALETTES = [
    {
        name: 'Purple Wave',
        colors: { primary: '#411183', accent: '#1F6DF2', accent_soft: '#93C5FD', secondary: '#6B7280', heavy: '#1F2937' }
    },
    {
        name: 'Ocean Blue',
        colors: { primary: '#0EA5E9', accent: '#3B82F6', accent_soft: '#93C5FD', secondary: '#64748B', heavy: '#1E293B' }
    },
    {
        name: 'Emerald Green',
        colors: { primary: '#10B981', accent: '#059669', accent_soft: '#6EE7B7', secondary: '#6B7280', heavy: '#1F2937' }
    },
    {
        name: 'Sunset Orange',
        colors: { primary: '#F59E0B', accent: '#F97316', accent_soft: '#FCD34D', secondary: '#78716C', heavy: '#292524' }
    },
    {
        name: 'Rose Pink',
        colors: { primary: '#EC4899', accent: '#F43F5E', accent_soft: '#FBCFE8', secondary: '#6B7280', heavy: '#1F2937' }
    },
    {
        name: 'Indigo Night',
        colors: { primary: '#6366F1', accent: '#8B5CF6', accent_soft: '#C4B5FD', secondary: '#64748B', heavy: '#1E293B' }
    },
];

const resolveOrganizationId = () => {
    const parts = window.location.pathname.split('/').filter(Boolean);
    const index = parts.indexOf('organizations');
    if (index >= 0 && parts[index + 1]) return parts[index + 1];
    return parts[parts.length - 1];
};

function FileUpload({ label, accept, onUpload, currentUrl, onDelete, className = '', description }) {
    const [isDragging, setIsDragging] = useState(false);
    const [uploading, setUploading] = useState(false);

    const handleDragOver = (e) => {
        e.preventDefault();
        setIsDragging(true);
    };

    const handleDragLeave = () => {
        setIsDragging(false);
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setIsDragging(false);
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFile(files[0]);
        }
    };

    const handleFileInput = (e) => {
        const files = e.target.files;
        if (files.length > 0) {
            handleFile(files[0]);
        }
    };

    const handleFile = (file) => {
        setUploading(true);
        onUpload(file).finally(() => {
            setUploading(false);
        });
    };

    return (
        <div className={className}>
            <label className="block text-sm font-semibold text-gray-700 mb-2">{label}</label>
            
            {currentUrl ? (
                <div className="relative inline-block">
                    <div className="relative group">
                        <img
                            src={currentUrl}
                            alt={label}
                            className="h-24 w-auto border-2 border-gray-200 rounded-lg shadow-sm"
                        />
                        <button
                            type="button"
                            onClick={onDelete}
                            className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full p-1.5 opacity-0 group-hover:opacity-100 transition-opacity hover:bg-red-600 shadow-md"
                        >
                            <X className="w-4 h-4" />
                        </button>
                    </div>
                    <p className="text-xs text-gray-500 mt-2">{description}</p>
                </div>
            ) : (
                <div
                    onDragOver={handleDragOver}
                    onDragLeave={handleDragLeave}
                    onDrop={handleDrop}
                    className={`
                        relative border-2 border-dashed rounded-lg p-8 transition-all cursor-pointer
                        ${isDragging ? 'border-blue-500 bg-blue-50' : 'border-gray-300 hover:border-gray-400 bg-gray-50'}
                        ${uploading ? 'opacity-50 pointer-events-none' : ''}
                    `}
                >
                    <input
                        type="file"
                        accept={accept}
                        onChange={handleFileInput}
                        className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                        disabled={uploading}
                    />
                    <div className="text-center">
                        <Upload className={`mx-auto h-12 w-12 ${isDragging ? 'text-blue-500' : 'text-gray-400'} mb-3`} />
                        <p className="text-sm font-medium text-gray-700">
                            {uploading ? 'Uploading...' : 'Click to upload or drag and drop'}
                        </p>
                        <p className="text-xs text-gray-500 mt-1">{description}</p>
                    </div>
                </div>
            )}
        </div>
    );
}

function ColorPicker({ label, value, onChange, description }) {
    const [showPalette, setShowPalette] = useState(false);
    
    const commonColors = [
        '#411183', '#1F6DF2', '#93C5FD', '#6B7280', '#1F2937',
        '#0EA5E9', '#3B82F6', '#10B981', '#F59E0B', '#EC4899',
        '#6366F1', '#8B5CF6', '#F97316', '#EF4444', '#14B8A6',
    ];

    return (
        <div className="relative">
            <label className="block text-sm font-semibold text-gray-700 mb-2">{label}</label>
            {description && <p className="text-xs text-gray-500 mb-2">{description}</p>}
            
            <div className="flex items-center gap-3">
                <div className="relative">
                    <input
                        type="color"
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                        className="h-12 w-12 rounded-lg border-2 border-gray-300 cursor-pointer shadow-sm hover:shadow-md transition"
                    />
                </div>

                <input
                    type="text"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono text-sm"
                    placeholder="#000000"
                />

                <button
                    type="button"
                    onClick={() => setShowPalette(!showPalette)}
                    className="p-3 border-2 border-gray-300 rounded-lg hover:bg-gray-50 transition"
                >
                    <PaletteIcon className="w-5 h-5 text-gray-600" />
                </button>
            </div>

            {showPalette && (
                <div className="absolute z-10 mt-2 p-4 bg-white border border-gray-200 rounded-lg shadow-xl">
                    <p className="text-xs font-semibold text-gray-700 mb-2">Quick Colors</p>
                    <div className="grid grid-cols-5 gap-2">
                        {commonColors.map((color) => (
                            <button
                                key={color}
                                type="button"
                                onClick={() => {
                                    onChange(color);
                                    setShowPalette(false);
                                }}
                                className="w-10 h-10 rounded-lg border-2 border-gray-200 hover:scale-110 transition-transform shadow-sm"
                                style={{ backgroundColor: color }}
                                title={color}
                            />
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}

function LivePreview({ data, organization }) {
    return (
        <div className="bg-white rounded-xl shadow-lg p-6 sticky top-6">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-bold text-gray-900 flex items-center gap-2">
                    <Eye className="w-5 h-5 text-blue-600" />
                    Live Preview
                </h3>
            </div>

            <div className="space-y-6">
                <div className="border-2 border-gray-200 rounded-lg p-6 bg-gray-50">
                    <p className="text-xs font-semibold text-gray-600 mb-3">Logo</p>
                    {organization?.settings?.branding?.logo_url ? (
                        <img
                            src={organization.settings.branding.logo_url}
                            alt="Logo Preview"
                            className="h-16 w-auto mx-auto"
                        />
                    ) : (
                        <div className="h-16 flex items-center justify-center text-gray-400 text-sm">
                            No logo uploaded
                        </div>
                    )}
                </div>

                <div className="border-2 border-gray-200 rounded-lg p-4 bg-gray-50">
                    <p className="text-xs font-semibold text-gray-600 mb-3">Theme Colors</p>
                    <div className="space-y-2">
                        {[
                            { label: 'Primary', key: 'theme.colors.primary' },
                            { label: 'Accent', key: 'theme.colors.accent' },
                            { label: 'Accent Soft', key: 'theme.colors.accent_soft' },
                        ].map((color) => (
                            <div key={color.key} className="flex items-center justify-between">
                                <span className="text-xs text-gray-600">{color.label}</span>
                                <div className="flex items-center gap-2">
                                    <div
                                        className="w-8 h-8 rounded border-2 border-white shadow-sm"
                                        style={{ backgroundColor: data[color.key] }}
                                    />
                                    <span className="text-xs font-mono text-gray-500">{data[color.key]}</span>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <div className="border-2 border-gray-200 rounded-lg p-4 bg-gray-50">
                    <p className="text-xs font-semibold text-gray-600 mb-3">Sample Button</p>
                    <button
                        className="w-full py-3 px-4 rounded-lg text-white font-semibold shadow-sm hover:shadow-md transition"
                        style={{ backgroundColor: data['theme.colors.primary'] }}
                    >
                        Primary Button
                    </button>
                    <button
                        className="w-full mt-2 py-3 px-4 rounded-lg text-white font-semibold shadow-sm hover:shadow-md transition"
                        style={{ backgroundColor: data['theme.colors.accent'] }}
                    >
                        Accent Button
                    </button>
                </div>

                <div className="border-2 border-gray-200 rounded-lg p-4 bg-gray-50">
                    <p className="text-xs font-semibold text-gray-600 mb-2">Organization Info</p>
                    <h4 className="font-bold text-gray-900">{data['branding.organization_name'] || 'Organization Name'}</h4>
                    <p className="text-sm text-gray-600 mt-1">{data['branding.tagline'] || 'Your tagline here'}</p>
                </div>
            </div>
        </div>
    );
}

export default function Branding() {
    const { showError, showSuccess } = useToast();
    const organizationId = resolveOrganizationId();
    const [activeTab, setActiveTab] = useState('branding');
    const [selectedPalette, setSelectedPalette] = useState(null);
    const [loading, setLoading] = useState(true);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const [organization, setOrganization] = useState(null);

    const [data, setData] = useState({
        'branding.organization_name': '',
        'branding.tagline': '',
        'branding.description': '',
        'theme.colors.primary': '#411183',
        'theme.colors.accent': '#1F6DF2',
        'theme.colors.accent_soft': '#93C5FD',
        'theme.colors.secondary': '#6B7280',
        'theme.colors.heavy': '#1F2937',
        'contact.email': '',
        'contact.phone': '',
        'contact.business_hours': '',
        'contact.address.line1': '',
        'contact.address.city': '',
        'contact.address.country': '',
        'social_media.facebook': '',
        'social_media.twitter': '',
        'social_media.instagram': '',
        'social_media.linkedin': '',
        'social_media.youtube': '',
        'email.from_name': '',
        'email.from_email': '',
        'email.header_color': '#411183',
        'email.button_color': '#1F6DF2',
        'email.footer_text': '',
        'email.footer_disclaimer': '',
        'theme.custom_css': '',
    });

    useEffect(() => {
        let mounted = true;

        const loadOrganization = async () => {
            try {
                setLoading(true);
                const response = await apiClient.get(`/superadmin/organizations/${organizationId}`, { useToken: true });
                if (!mounted) return;
                const org = response?.data?.organization;
                if (!org) throw new Error('Organization not found.');
                setOrganization(org);
                const settings = org.settings || {};
                const branding = settings?.branding || {};
                const brandingColors = branding?.colors || {};
                const brandingContact = branding?.contact || {};
                const brandingSocial = branding?.social || {};

                setData((prev) => ({
                    ...prev,
                    'branding.organization_name': branding?.organization_name || branding?.name || org?.name || '',
                    'branding.tagline': branding?.tagline || '',
                    'branding.description': branding?.description || '',
                    'theme.colors.primary': settings?.theme?.colors?.primary || brandingColors?.primary || prev['theme.colors.primary'],
                    'theme.colors.accent': settings?.theme?.colors?.accent || brandingColors?.accent || prev['theme.colors.accent'],
                    'theme.colors.accent_soft': settings?.theme?.colors?.accent_soft || brandingColors?.accent_soft || prev['theme.colors.accent_soft'],
                    'theme.colors.secondary': settings?.theme?.colors?.secondary || brandingColors?.secondary || prev['theme.colors.secondary'],
                    'theme.colors.heavy': settings?.theme?.colors?.heavy || brandingColors?.heavy || prev['theme.colors.heavy'],
                    'contact.email': settings?.contact?.email || brandingContact?.email || '',
                    'contact.phone': settings?.contact?.phone || brandingContact?.phone || '',
                    'contact.business_hours': settings?.contact?.business_hours || brandingContact?.business_hours || '',
                    'contact.address.line1': settings?.contact?.address?.line1 || brandingContact?.address?.line1 || '',
                    'contact.address.city': settings?.contact?.address?.city || brandingContact?.address?.city || '',
                    'contact.address.country': settings?.contact?.address?.country || brandingContact?.address?.country || '',
                    'social_media.facebook': settings?.social_media?.facebook || brandingSocial?.facebook || '',
                    'social_media.twitter': settings?.social_media?.twitter || brandingSocial?.twitter || '',
                    'social_media.instagram': settings?.social_media?.instagram || brandingSocial?.instagram || '',
                    'social_media.linkedin': settings?.social_media?.linkedin || brandingSocial?.linkedin || '',
                    'social_media.youtube': settings?.social_media?.youtube || brandingSocial?.youtube || '',
                    'email.from_name': settings?.email?.from_name || '',
                    'email.from_email': settings?.email?.from_email || '',
                    'email.header_color': settings?.email?.header_color || prev['email.header_color'],
                    'email.button_color': settings?.email?.button_color || prev['email.button_color'],
                    'email.footer_text': settings?.email?.footer_text || '',
                    'email.footer_disclaimer': settings?.email?.footer_disclaimer || '',
                    'theme.custom_css': settings?.theme?.custom_css || branding?.custom_css || '',
                }));
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load organization settings.');
            } finally {
                if (mounted) setLoading(false);
            }
        };

        loadOrganization();

        return () => {
            mounted = false;
        };
    }, [showError, organizationId]);

    const updateField = (key, value) => {
        setData((prev) => ({ ...prev, [key]: value }));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            await apiClient.put(`/superadmin/organizations/${organizationId}/branding`, data, { useToken: true });
            showSuccess('Branding updated.');
        } catch (error) {
            const fieldErrors = extractValidationErrors(error);
            setErrors(fieldErrors);
            showError(error.message || 'Unable to update branding.');
        } finally {
            setProcessing(false);
        }
    };

    const handleLogoUpload = async (file, type) => {
        const formData = new FormData();
        formData.append('logo', file);
        formData.append('type', type);
        const response = await apiClient.post(`/superadmin/organizations/${organizationId}/branding/logo`, formData, { useToken: true });
        if (response?.data?.logo_url) {
            setOrganization((prev) => ({
                ...prev,
                settings: {
                    ...prev.settings,
                    branding: {
                        ...prev.settings?.branding,
                        ...(type === 'dark' ? { logo_dark_url: response.data.logo_url } : { logo_url: response.data.logo_url }),
                    },
                },
            }));
        }
    };

    const handleFaviconUpload = async (file) => {
        const formData = new FormData();
        formData.append('favicon', file);
        const response = await apiClient.post(`/superadmin/organizations/${organizationId}/branding/favicon`, formData, { useToken: true });
        if (response?.data?.favicon_url) {
            setOrganization((prev) => ({
                ...prev,
                settings: {
                    ...prev.settings,
                    branding: {
                        ...prev.settings?.branding,
                        favicon_url: response.data.favicon_url,
                    },
                },
            }));
        }
    };

    const handleDeleteAsset = async (assetType) => {
        if (!confirm(`Are you sure you want to delete this ${assetType}?`)) return;
        await apiClient.delete(`/superadmin/organizations/${organizationId}/branding/asset`, {
            body: { asset_type: assetType },
            useToken: true,
        });
        setOrganization((prev) => ({
            ...prev,
            settings: {
                ...prev.settings,
                branding: {
                    ...prev.settings?.branding,
                    ...(assetType === 'logo' ? { logo_url: null } : {}),
                    ...(assetType === 'logo_dark' ? { logo_dark_url: null } : {}),
                    ...(assetType === 'favicon' ? { favicon_url: null } : {}),
                },
            },
        }));
    };

    const applyPalette = (palette) => {
        setData((prev) => ({
            ...prev,
            'theme.colors.primary': palette.colors.primary,
            'theme.colors.accent': palette.colors.accent,
            'theme.colors.accent_soft': palette.colors.accent_soft,
            'theme.colors.secondary': palette.colors.secondary,
            'theme.colors.heavy': palette.colors.heavy,
        }));
        setSelectedPalette(palette.name);
        setTimeout(() => setSelectedPalette(null), 2000);
    };

    const tabs = [
        { id: 'branding', name: 'Branding', icon: PaletteIcon },
        { id: 'theme', name: 'Theme Colors', icon: PaletteIcon },
        { id: 'contact', name: 'Contact Info', icon: Mail },
        { id: 'social', name: 'Social Media', icon: Globe },
        { id: 'email', name: 'Email Settings', icon: Mail },
        { id: 'advanced', name: 'Advanced', icon: Settings },
    ];

    if (loading) {
        return (
            <SuperAdminLayout>
                <div className="text-gray-600">Loading branding settings...</div>
            </SuperAdminLayout>
        );
    }

    return (
        <SuperAdminLayout>
            <div className="py-8 bg-gray-50 min-h-screen">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    <div className="mb-8">
                        <h1 className="text-4xl font-bold text-gray-900">Organization Branding</h1>
                        <p className="mt-2 text-gray-600">
                            Customize the look and feel for <span className="font-semibold text-gray-900">{organization?.name}</span>
                        </p>
                    </div>

                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <div className="lg:col-span-2">
                            <div className="bg-white rounded-xl shadow-sm border border-gray-200 mb-6 overflow-hidden">
                                <div className="border-b border-gray-200">
                                    <nav className="flex overflow-x-auto">
                                        {tabs.map((tab) => (
                                            <button
                                                key={tab.id}
                                                onClick={() => setActiveTab(tab.id)}
                                                className={`
                                                    flex items-center gap-2 px-6 py-4 font-medium text-sm whitespace-nowrap transition
                                                    ${activeTab === tab.id
                                                        ? 'border-b-2 border-blue-600 text-blue-600 bg-blue-50'
                                                        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50'
                                                    }
                                                `}
                                            >
                                                <tab.icon className="w-4 h-4" />
                                                {tab.name}
                                            </button>
                                        ))}
                                    </nav>
                                </div>

                                <form onSubmit={handleSubmit} className="p-8">
                                    {activeTab === 'branding' && (
                                        <div className="space-y-8">
                                            <div>
                                                <h3 className="text-2xl font-bold text-gray-900 mb-6">Branding Assets</h3>
                                                <div className="space-y-6">
                                                    <div>
                                                        <label className="block text-sm font-semibold text-gray-700 mb-2">
                                                            Organization Name
                                                        </label>
                                                        <input
                                                            type="text"
                                                            value={data['branding.organization_name']}
                                                            onChange={(e) => updateField('branding.organization_name', e.target.value)}
                                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                            placeholder="Enter organization name"
                                                        />
                                                    </div>

                                                    <div>
                                                        <label className="block text-sm font-semibold text-gray-700 mb-2">
                                                            Tagline
                                                        </label>
                                                        <input
                                                            type="text"
                                                            value={data['branding.tagline']}
                                                            onChange={(e) => updateField('branding.tagline', e.target.value)}
                                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                            placeholder="Enter tagline"
                                                        />
                                                    </div>

                                                    <div>
                                                        <label className="block text-sm font-semibold text-gray-700 mb-2">
                                                            Description
                                                        </label>
                                                        <textarea
                                                            value={data['branding.description']}
                                                            onChange={(e) => updateField('branding.description', e.target.value)}
                                                            rows={4}
                                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                            placeholder="Enter organization description"
                                                        />
                                                    </div>

                                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                        <FileUpload
                                                            label="Logo (Light Mode)"
                                                            accept="image/png,image/svg+xml,image/webp,image/jpeg"
                                                            onUpload={(file) => handleLogoUpload(file, 'light')}
                                                            currentUrl={organization?.settings?.branding?.logo_url}
                                                            onDelete={() => handleDeleteAsset('logo')}
                                                            description="PNG, SVG, WEBP, JPG (max 2MB)"
                                                        />

                                                        <FileUpload
                                                            label="Logo (Dark Mode)"
                                                            accept="image/png,image/svg+xml,image/webp,image/jpeg"
                                                            onUpload={(file) => handleLogoUpload(file, 'dark')}
                                                            currentUrl={organization?.settings?.branding?.logo_dark_url}
                                                            onDelete={() => handleDeleteAsset('logo_dark')}
                                                            description="Optional for dark themes"
                                                        />
                                                    </div>

                                                    <FileUpload
                                                        label="Favicon"
                                                        accept="image/x-icon,image/png"
                                                        onUpload={handleFaviconUpload}
                                                        currentUrl={organization?.settings?.branding?.favicon_url}
                                                        onDelete={() => handleDeleteAsset('favicon')}
                                                        description="ICO or PNG (max 100KB, 32x32 or 16x16)"
                                                        className="max-w-md"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {activeTab === 'theme' && (
                                        <div className="space-y-8">
                                            <div>
                                                <h3 className="text-2xl font-bold text-gray-900 mb-2">Theme Colors</h3>
                                                <p className="text-gray-600 mb-6">
                                                    Choose colors that represent your brand. These will be used throughout the application.
                                                </p>

                                                <div className="mb-8 p-6 bg-gradient-to-br from-blue-50 to-purple-50 rounded-xl border border-blue-200">
                                                    <h4 className="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2">
                                                        <PaletteIcon className="w-4 h-4" />
                                                        Quick Palettes
                                                    </h4>
                                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        {COLOR_PALETTES.map((palette) => (
                                                            <button
                                                                key={palette.name}
                                                                type="button"
                                                                onClick={() => applyPalette(palette)}
                                                                className={`p-4 rounded-lg border-2 transition-all text-left ${
                                                                    selectedPalette === palette.name
                                                                        ? 'border-green-500 bg-green-50'
                                                                        : 'border-gray-200 hover:border-blue-300 bg-white'
                                                                }`}
                                                            >
                                                                <div className="flex items-center justify-between">
                                                                    <span className="font-medium text-gray-900">{palette.name}</span>
                                                                    {selectedPalette === palette.name && (
                                                                        <Check className="w-4 h-4 text-green-600" />
                                                                    )}
                                                                </div>
                                                                <div className="flex gap-2 mt-3">
                                                                    {Object.values(palette.colors).map((color, i) => (
                                                                        <div
                                                                            key={i}
                                                                            className="w-6 h-6 rounded-full border-2 border-white shadow-sm"
                                                                            style={{ backgroundColor: color }}
                                                                        />
                                                                    ))}
                                                                </div>
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>

                                                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                    <ColorPicker
                                                        label="Primary Color"
                                                        value={data['theme.colors.primary']}
                                                        onChange={(value) => updateField('theme.colors.primary', value)}
                                                        description="Main brand color"
                                                    />
                                                    <ColorPicker
                                                        label="Accent Color"
                                                        value={data['theme.colors.accent']}
                                                        onChange={(value) => updateField('theme.colors.accent', value)}
                                                        description="Secondary brand color"
                                                    />
                                                    <ColorPicker
                                                        label="Accent Soft"
                                                        value={data['theme.colors.accent_soft']}
                                                        onChange={(value) => updateField('theme.colors.accent_soft', value)}
                                                        description="Soft accent for backgrounds"
                                                    />
                                                    <ColorPicker
                                                        label="Secondary Color"
                                                        value={data['theme.colors.secondary']}
                                                        onChange={(value) => updateField('theme.colors.secondary', value)}
                                                    />
                                                    <ColorPicker
                                                        label="Heavy Color"
                                                        value={data['theme.colors.heavy']}
                                                        onChange={(value) => updateField('theme.colors.heavy', value)}
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {activeTab === 'contact' && (
                                        <div className="space-y-6">
                                            <div>
                                                <h3 className="text-2xl font-bold text-gray-900 mb-2">Contact Info</h3>
                                                <p className="text-gray-600 mb-6">Set support contact details.</p>
                                            </div>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                                                    <input
                                                        type="email"
                                                        value={data['contact.email']}
                                                        onChange={(e) => updateField('contact.email', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Phone</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.phone']}
                                                        onChange={(e) => updateField('contact.phone', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Business Hours</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.business_hours']}
                                                        onChange={(e) => updateField('contact.business_hours', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Address Line 1</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.address.line1']}
                                                        onChange={(e) => updateField('contact.address.line1', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">City</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.address.city']}
                                                        onChange={(e) => updateField('contact.address.city', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Country</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.address.country']}
                                                        onChange={(e) => updateField('contact.address.country', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {activeTab === 'social' && (
                                        <div className="space-y-6">
                                            <div>
                                                <h3 className="text-2xl font-bold text-gray-900 mb-2">Social Media</h3>
                                                <p className="text-gray-600 mb-6">Add social media links.</p>
                                            </div>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                {['facebook', 'twitter', 'instagram', 'linkedin', 'youtube'].map((key) => (
                                                    <div key={key}>
                                                        <label className="block text-sm font-semibold text-gray-700 mb-2">
                                                            {key.charAt(0).toUpperCase() + key.slice(1)}
                                                        </label>
                                                        <input
                                                            type="url"
                                                            value={data[`social_media.${key}`]}
                                                            onChange={(e) => updateField(`social_media.${key}`, e.target.value)}
                                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        />
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}

                                    {activeTab === 'email' && (
                                        <div className="space-y-6">
                                            <div>
                                                <h3 className="text-2xl font-bold text-gray-900 mb-2">Email Settings</h3>
                                                <p className="text-gray-600 mb-6">Configure email branding.</p>
                                            </div>
                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">From Name</label>
                                                    <input
                                                        type="text"
                                                        value={data['email.from_name']}
                                                        onChange={(e) => updateField('email.from_name', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">From Email</label>
                                                    <input
                                                        type="email"
                                                        value={data['email.from_email']}
                                                        onChange={(e) => updateField('email.from_email', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <ColorPicker
                                                    label="Header Color"
                                                    value={data['email.header_color']}
                                                    onChange={(value) => updateField('email.header_color', value)}
                                                />
                                                <ColorPicker
                                                    label="Button Color"
                                                    value={data['email.button_color']}
                                                    onChange={(value) => updateField('email.button_color', value)}
                                                />
                                                <div className="md:col-span-2">
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Footer Text</label>
                                                    <textarea
                                                        value={data['email.footer_text']}
                                                        onChange={(e) => updateField('email.footer_text', e.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                                <div className="md:col-span-2">
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Footer Disclaimer</label>
                                                    <textarea
                                                        value={data['email.footer_disclaimer']}
                                                        onChange={(e) => updateField('email.footer_disclaimer', e.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {activeTab === 'advanced' && (
                                        <div className="space-y-6">
                                            <div>
                                                <h3 className="text-2xl font-bold text-gray-900 mb-2">Advanced</h3>
                                                <p className="text-gray-600 mb-6">Custom CSS overrides.</p>
                                            </div>
                                            <div>
                                                <label className="block text-sm font-semibold text-gray-700 mb-2">Custom CSS</label>
                                                <textarea
                                                    value={data['theme.custom_css']}
                                                    onChange={(e) => updateField('theme.custom_css', e.target.value)}
                                                    rows={8}
                                                    className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono text-sm"
                                                    placeholder="/* Custom CSS */"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    <div className="mt-8 flex items-center gap-4">
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="flex items-center gap-2 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition disabled:opacity-50"
                                        >
                                            {processing ? 'Saving...' : 'Save Changes'}
                                        </button>
                                        {Object.keys(errors).length > 0 && (
                                            <div className="flex items-center gap-2 text-red-600 text-sm">
                                                Please fix the highlighted fields.
                                            </div>
                                        )}
                                    </div>
                                </form>
                            </div>
                        </div>

                        <LivePreview data={data} organization={organization} />
                    </div>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
