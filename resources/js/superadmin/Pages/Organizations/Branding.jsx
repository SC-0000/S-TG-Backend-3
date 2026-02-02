import React, { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { Upload, X, Eye, Check, Palette as PaletteIcon, Mail, Globe, Settings } from 'lucide-react';

// Predefined color palettes
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

// File Upload Component with Drag & Drop
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

// Color Picker Component with Palette
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
                {/* Color Preview */}
                <div className="relative">
                    <input
                        type="color"
                        value={value}
                        onChange={(e) => onChange(e.target.value)}
                        className="h-12 w-12 rounded-lg border-2 border-gray-300 cursor-pointer shadow-sm hover:shadow-md transition"
                    />
                </div>

                {/* Hex Input */}
                <input
                    type="text"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="flex-1 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono text-sm"
                    placeholder="#000000"
                />

                {/* Palette Button */}
                <button
                    type="button"
                    onClick={() => setShowPalette(!showPalette)}
                    className="p-3 border-2 border-gray-300 rounded-lg hover:bg-gray-50 transition"
                >
                    <PaletteIcon className="w-5 h-5 text-gray-600" />
                </button>
            </div>

            {/* Color Palette Dropdown */}
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

// Live Preview Component
function LivePreview({ data, organization }) {
    return (
        <div className="bg-white rounded-xl shadow-lg p-6 sticky top-6">
            <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-bold text-gray-900 flex items-center gap-2">
                    <Eye className="w-5 h-5 text-blue-600" />
                    Live Preview
                </h3>
            </div>

            {/* Preview Content */}
            <div className="space-y-6">
                {/* Logo Preview */}
                <div className="border-2 border-gray-200 rounded-lg p-6 bg-gray-50">
                    <p className="text-xs font-semibold text-gray-600 mb-3">Logo</p>
                    {organization.settings?.branding?.logo_url ? (
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

                {/* Theme Colors Preview */}
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

                {/* Sample Button Preview */}
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

                {/* Organization Info Preview */}
                <div className="border-2 border-gray-200 rounded-lg p-4 bg-gray-50">
                    <p className="text-xs font-semibold text-gray-600 mb-2">Organization Info</p>
                    <h4 className="font-bold text-gray-900">{data['branding.organization_name'] || 'Organization Name'}</h4>
                    <p className="text-sm text-gray-600 mt-1">{data['branding.tagline'] || 'Your tagline here'}</p>
                </div>
            </div>
        </div>
    );
}

export default function Branding({ organization }) {
    const [activeTab, setActiveTab] = useState('branding');
    const [selectedPalette, setSelectedPalette] = useState(null);

    const { data, setData, post, processing, errors } = useForm({
        // Branding
        'branding.organization_name': organization.settings?.branding?.organization_name || '',
        'branding.tagline': organization.settings?.branding?.tagline || '',
        'branding.description': organization.settings?.branding?.description || '',
        
        // Theme Colors
        'theme.colors.primary': organization.settings?.theme?.colors?.primary || '#411183',
        'theme.colors.accent': organization.settings?.theme?.colors?.accent || '#1F6DF2',
        'theme.colors.accent_soft': organization.settings?.theme?.colors?.accent_soft || '#93C5FD',
        'theme.colors.secondary': organization.settings?.theme?.colors?.secondary || '#6B7280',
        'theme.colors.heavy': organization.settings?.theme?.colors?.heavy || '#1F2937',
        
        // Contact
        'contact.email': organization.settings?.contact?.email || '',
        'contact.phone': organization.settings?.contact?.phone || '',
        'contact.business_hours': organization.settings?.contact?.business_hours || '',
        'contact.address.line1': organization.settings?.contact?.address?.line1 || '',
        'contact.address.city': organization.settings?.contact?.address?.city || '',
        'contact.address.country': organization.settings?.contact?.address?.country || '',
        
        // Social Media
        'social_media.facebook': organization.settings?.social_media?.facebook || '',
        'social_media.twitter': organization.settings?.social_media?.twitter || '',
        'social_media.instagram': organization.settings?.social_media?.instagram || '',
        'social_media.linkedin': organization.settings?.social_media?.linkedin || '',
        'social_media.youtube': organization.settings?.social_media?.youtube || '',
        
        // Email Settings
        'email.from_name': organization.settings?.email?.from_name || '',
        'email.from_email': organization.settings?.email?.from_email || '',
        'email.header_color': organization.settings?.email?.header_color || '#411183',
        'email.button_color': organization.settings?.email?.button_color || '#1F6DF2',
        'email.footer_text': organization.settings?.email?.footer_text || '',
        'email.footer_disclaimer': organization.settings?.email?.footer_disclaimer || '',
        
        // Custom CSS
        'theme.custom_css': organization.settings?.theme?.custom_css || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('superadmin.organizations.branding.update', organization.id), {
            preserveScroll: true,
        });
    };

    const handleLogoUpload = async (file, type) => {
        const formData = new FormData();
        formData.append('logo', file);
        formData.append('type', type);

        return new Promise((resolve) => {
            router.post(route('superadmin.organizations.branding.upload-logo', organization.id), formData, {
                preserveScroll: true,
                onFinish: () => resolve(),
            });
        });
    };

    const handleFaviconUpload = async (file) => {
        const formData = new FormData();
        formData.append('favicon', file);

        return new Promise((resolve) => {
            router.post(route('superadmin.organizations.branding.upload-favicon', organization.id), formData, {
                preserveScroll: true,
                onFinish: () => resolve(),
            });
        });
    };

    const handleDeleteAsset = (assetType) => {
        if (!confirm(`Are you sure you want to delete this ${assetType}?`)) return;

        router.delete(route('superadmin.organizations.branding.delete-asset', organization.id), {
            data: { asset_type: assetType },
            preserveScroll: true,
        });
    };

    const applyPalette = (palette) => {
        setData({
            ...data,
            'theme.colors.primary': palette.colors.primary,
            'theme.colors.accent': palette.colors.accent,
            'theme.colors.accent_soft': palette.colors.accent_soft,
            'theme.colors.secondary': palette.colors.secondary,
            'theme.colors.heavy': palette.colors.heavy,
        });
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

    return (
        <SuperAdminLayout>
            <Head title={`Branding - ${organization.name}`} />

            <div className="py-8 bg-gray-50 min-h-screen">
                <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                    {/* Header */}
                    <div className="mb-8">
                        <h1 className="text-4xl font-bold text-gray-900">Organization Branding</h1>
                        <p className="mt-2 text-gray-600">
                            Customize the look and feel for <span className="font-semibold text-gray-900">{organization.name}</span>
                        </p>
                    </div>

                    {/* Main Grid */}
                    <div className="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        {/* Left Column - Form */}
                        <div className="lg:col-span-2">
                            {/* Tabs */}
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
                                    {/* Branding Tab */}
                                    {activeTab === 'branding' && (
                                        <div className="space-y-8">
                                            <div>
                                                <h3 className="text-2xl font-bold text-gray-900 mb-6">Branding Assets</h3>
                                                
                                                <div className="space-y-6">
                                                    {/* Organization Name */}
                                                    <div>
                                                        <label className="block text-sm font-semibold text-gray-700 mb-2">
                                                            Organization Name
                                                        </label>
                                                        <input
                                                            type="text"
                                                            value={data['branding.organization_name']}
                                                            onChange={(e) => setData('branding.organization_name', e.target.value)}
                                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                            placeholder="Enter organization name"
                                                        />
                                                    </div>

                                                    {/* Tagline */}
                                                    <div>
                                                        <label className="block text-sm font-semibold text-gray-700 mb-2">
                                                            Tagline
                                                        </label>
                                                        <input
                                                            type="text"
                                                            value={data['branding.tagline']}
                                                            onChange={(e) => setData('branding.tagline', e.target.value)}
                                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                            placeholder="Enter tagline"
                                                        />
                                                    </div>

                                                    {/* Description */}
                                                    <div>
                                                        <label className="block text-sm font-semibold text-gray-700 mb-2">
                                                            Description
                                                        </label>
                                                        <textarea
                                                            value={data['branding.description']}
                                                            onChange={(e) => setData('branding.description', e.target.value)}
                                                            rows={4}
                                                            className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                            placeholder="Enter organization description"
                                                        />
                                                    </div>

                                                    {/* Logo Uploads */}
                                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                        <FileUpload
                                                            label="Logo (Light Mode)"
                                                            accept="image/png,image/svg+xml,image/webp,image/jpeg"
                                                            onUpload={(file) => handleLogoUpload(file, 'light')}
                                                            currentUrl={organization.settings?.branding?.logo_url}
                                                            onDelete={() => handleDeleteAsset('logo')}
                                                            description="PNG, SVG, WEBP, JPG (max 2MB)"
                                                        />

                                                        <FileUpload
                                                            label="Logo (Dark Mode)"
                                                            accept="image/png,image/svg+xml,image/webp,image/jpeg"
                                                            onUpload={(file) => handleLogoUpload(file, 'dark')}
                                                            currentUrl={organization.settings?.branding?.logo_dark_url}
                                                            onDelete={() => handleDeleteAsset('logo_dark')}
                                                            description="Optional for dark themes"
                                                        />
                                                    </div>

                                                    {/* Favicon */}
                                                    <FileUpload
                                                        label="Favicon"
                                                        accept="image/x-icon,image/png"
                                                        onUpload={handleFaviconUpload}
                                                        currentUrl={organization.settings?.branding?.favicon_url}
                                                        onDelete={() => handleDeleteAsset('favicon')}
                                                        description="ICO or PNG (max 100KB, 32x32 or 16x16)"
                                                        className="max-w-md"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Theme Colors Tab */}
                                    {activeTab === 'theme' && (
                                        <div className="space-y-8">
                                            <div>
                                                <h3 className="text-2xl font-bold text-gray-900 mb-2">Theme Colors</h3>
                                                <p className="text-gray-600 mb-6">
                                                    Choose colors that represent your brand. These will be used throughout the application.
                                                </p>

                                                {/* Color Palettes */}
                                                <div className="mb-8 p-6 bg-gradient-to-br from-blue-50 to-purple-50 rounded-xl border border-blue-200">
                                                    <h4 className="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2">
                                                        <PaletteIcon className="w-4 h-4" />
                                                        Quick Start Palettes
                                                    </h4>
                                                    <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                                                        {COLOR_PALETTES.map((palette) => (
                                                            <button
                                                                key={palette.name}
                                                                type="button"
                                                                onClick={() => applyPalette(palette)}
                                                                className={`
                                                                    p-4 rounded-lg border-2 transition-all hover:shadow-md
                                                                    ${selectedPalette === palette.name
                                                                        ? 'border-green-500 bg-green-50'
                                                                        : 'border-gray-200 bg-white hover:border-blue-400'
                                                                    }
                                                                `}
                                                            >
                                                                <div className="flex justify-between items-center mb-2">
                                                                    <span className="text-xs font-semibold text-gray-700">{palette.name}</span>
                                                                    {selectedPalette === palette.name && (
                                                                        <Check className="w-4 h-4 text-green-600" />
                                                                    )}
                                                                </div>
                                                                <div className="flex gap-1">
                                                                    {Object.values(palette.colors).map((color, idx) => (
                                                                        <div
                                                                            key={idx}
                                                                            className="w-8 h-8 rounded shadow-sm"
                                                                            style={{ backgroundColor: color }}
                                                                        />
                                                                    ))}
                                                                </div>
                                                            </button>
                                                        ))}
                                                    </div>
                                                </div>

                                                {/* Individual Color Pickers */}
                                                <div className="space-y-6">
                                                    <ColorPicker
                                                        label="Primary Color"
                                                        value={data['theme.colors.primary']}
                                                        onChange={(value) => setData('theme.colors.primary', value)}
                                                        description="Main brand color used for buttons, links, and key elements"
                                                    />

                                                    <ColorPicker
                                                        label="Accent Color"
                                                        value={data['theme.colors.accent']}
                                                        onChange={(value) => setData('theme.colors.accent', value)}
                                                        description="Secondary highlights and call-to-action elements"
                                                    />

                                                    <ColorPicker
                                                        label="Accent Soft"
                                                        value={data['theme.colors.accent_soft']}
                                                        onChange={(value) => setData('theme.colors.accent_soft', value)}
                                                        description="Light accent for backgrounds and subtle highlights"
                                                    />

                                                    <ColorPicker
                                                        label="Secondary Color"
                                                        value={data['theme.colors.secondary']}
                                                        onChange={(value) => setData('theme.colors.secondary', value)}
                                                        description="Supporting color for less prominent elements"
                                                    />

                                                    <ColorPicker
                                                        label="Heavy Color"
                                                        value={data['theme.colors.heavy']}
                                                        onChange={(value) => setData('theme.colors.heavy', value)}
                                                        description="Dark color for text and strong contrasts"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Contact Info Tab */}
                                    {activeTab === 'contact' && (
                                        <div className="space-y-6">
                                            <h3 className="text-2xl font-bold text-gray-900 mb-6">Contact Information</h3>

                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Email</label>
                                                    <input
                                                        type="email"
                                                        value={data['contact.email']}
                                                        onChange={(e) => setData('contact.email', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="contact@example.com"
                                                    />
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Phone</label>
                                                    <input
                                                        type="tel"
                                                        value={data['contact.phone']}
                                                        onChange={(e) => setData('contact.phone', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="+1 (555) 123-4567"
                                                    />
                                                </div>

                                                <div className="md:col-span-2">
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Business Hours</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.business_hours']}
                                                        onChange={(e) => setData('contact.business_hours', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="Mon-Fri 9AM-5PM EST"
                                                    />
                                                </div>

                                                <div className="md:col-span-2">
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Address</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.address.line1']}
                                                        onChange={(e) => setData('contact.address.line1', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="123 Main Street"
                                                    />
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">City</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.address.city']}
                                                        onChange={(e) => setData('contact.address.city', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="New York"
                                                    />
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Country</label>
                                                    <input
                                                        type="text"
                                                        value={data['contact.address.country']}
                                                        onChange={(e) => setData('contact.address.country', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="United States"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Social Media Tab */}
                                    {activeTab === 'social' && (
                                        <div className="space-y-6">
                                            <h3 className="text-2xl font-bold text-gray-900 mb-6">Social Media Links</h3>

                                            {[
                                                { key: 'social_media.facebook', label: 'Facebook', placeholder: 'https://facebook.com/...' },
                                                { key: 'social_media.twitter', label: 'Twitter', placeholder: 'https://twitter.com/...' },
                                                { key: 'social_media.instagram', label: 'Instagram', placeholder: 'https://instagram.com/...' },
                                                { key: 'social_media.linkedin', label: 'LinkedIn', placeholder: 'https://linkedin.com/...' },
                                                { key: 'social_media.youtube', label: 'YouTube', placeholder: 'https://youtube.com/...' },
                                            ].map((social) => (
                                                <div key={social.key}>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">{social.label}</label>
                                                    <input
                                                        type="url"
                                                        value={data[social.key]}
                                                        onChange={(e) => setData(social.key, e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder={social.placeholder}
                                                    />
                                                </div>
                                            ))}
                                        </div>
                                    )}

                                    {/* Email Settings Tab */}
                                    {activeTab === 'email' && (
                                        <div className="space-y-6">
                                            <h3 className="text-2xl font-bold text-gray-900 mb-6">Email Branding</h3>

                                            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">From Name</label>
                                                    <input
                                                        type="text"
                                                        value={data['email.from_name']}
                                                        onChange={(e) => setData('email.from_name', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="Your Organization"
                                                    />
                                                </div>

                                                <div>
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">From Email</label>
                                                    <input
                                                        type="email"
                                                        value={data['email.from_email']}
                                                        onChange={(e) => setData('email.from_email', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="noreply@example.com"
                                                    />
                                                </div>

                                                <div>
                                                    <ColorPicker
                                                        label="Email Header Color"
                                                        value={data['email.header_color']}
                                                        onChange={(value) => setData('email.header_color', value)}
                                                        description="Color for email headers"
                                                    />
                                                </div>

                                                <div>
                                                    <ColorPicker
                                                        label="Email Button Color"
                                                        value={data['email.button_color']}
                                                        onChange={(value) => setData('email.button_color', value)}
                                                        description="Color for call-to-action buttons"
                                                    />
                                                </div>

                                                <div className="md:col-span-2">
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Footer Text</label>
                                                    <input
                                                        type="text"
                                                        value={data['email.footer_text']}
                                                        onChange={(e) => setData('email.footer_text', e.target.value)}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="© 2025. All rights reserved."
                                                    />
                                                </div>

                                                <div className="md:col-span-2">
                                                    <label className="block text-sm font-semibold text-gray-700 mb-2">Footer Disclaimer</label>
                                                    <textarea
                                                        value={data['email.footer_disclaimer']}
                                                        onChange={(e) => setData('email.footer_disclaimer', e.target.value)}
                                                        rows={3}
                                                        className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                                        placeholder="Optional legal or privacy disclaimer"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {/* Advanced Tab */}
                                    {activeTab === 'advanced' && (
                                        <div className="space-y-6">
                                            <h3 className="text-2xl font-bold text-gray-900 mb-6">Advanced Settings</h3>

                                            <div className="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                                <p className="text-sm text-yellow-800">
                                                    <strong>⚠️ Warning:</strong> Custom CSS can override default styles. Use with caution.
                                                </p>
                                            </div>

                                            <div>
                                                <label className="block text-sm font-semibold text-gray-700 mb-2">
                                                    Custom CSS
                                                </label>
                                                <p className="text-xs text-gray-500 mb-3">
                                                    Add custom CSS to override default styles and create unique designs.
                                                </p>
                                                <textarea
                                                    value={data['theme.custom_css']}
                                                    onChange={(e) => setData('theme.custom_css', e.target.value)}
                                                    rows={16}
                                                    className="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 font-mono text-sm"
                                                    placeholder=".my-custom-class {&#10;  color: #333;&#10;  font-weight: bold;&#10;}"
                                                />
                                            </div>
                                        </div>
                                    )}

                                    {/* Save Button */}
                                    <div className="mt-8 flex justify-end gap-4">
                                        <button
                                            type="button"
                                            onClick={() => router.visit(route('superadmin.organizations.show', organization.id))}
                                            className="px-6 py-3 border-2 border-gray-300 rounded-lg font-semibold text-gray-700 hover:bg-gray-50 transition"
                                        >
                                            Cancel
                                        </button>
                                        <button
                                            type="submit"
                                            disabled={processing}
                                            className="px-8 py-3 bg-gradient-to-r from-blue-600 to-purple-600 text-white rounded-lg font-semibold shadow-lg hover:shadow-xl transition disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            {processing ? (
                                                <span className="flex items-center gap-2">
                                                    <svg className="animate-spin h-5 w-5" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" fill="none" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                                                    </svg>
                                                    Saving...
                                                </span>
                                            ) : (
                                                'Save Changes'
                                            )}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {/* Right Column - Live Preview */}
                        <div className="lg:col-span-1">
                            <LivePreview data={data} organization={organization} />
                        </div>
                    </div>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
