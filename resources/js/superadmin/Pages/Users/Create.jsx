import React, { useEffect, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { ArrowLeft, Save, X } from 'lucide-react';
import { apiClient, extractValidationErrors } from '@/api';
import { useToast } from '@/contexts/ToastContext';

export default function CreateUser() {
    const { showError, showSuccess } = useToast();
    const [organizations, setOrganizations] = useState([]);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const [data, setData] = useState({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: 'parent',
        status: 'active',
        address_line1: '',
        address_line2: '',
        mobile_number: '',
        current_organization_id: '',
        send_credentials: true,
    });

    useEffect(() => {
        let mounted = true;

        const loadOrganizations = async () => {
            try {
                const response = await apiClient.get('/superadmin/organizations', {
                    params: { per_page: 200 },
                    useToken: true,
                });
                if (!mounted) return;
                setOrganizations(Array.isArray(response?.data) ? response.data : []);
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load organizations.');
            }
        };

        loadOrganizations();

        return () => {
            mounted = false;
        };
    }, [showError]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            const payload = {
                name: data.name,
                email: data.email,
                password: data.password,
                role: data.role,
                current_organization_id: data.current_organization_id || null,
            };

            await apiClient.post('/superadmin/users', payload, { useToken: true });
            showSuccess('User created.');
            window.location.href = '/superadmin/users';
        } catch (error) {
            const fieldErrors = extractValidationErrors(error);
            setErrors(fieldErrors);
            showError(error.message || 'Unable to create user.');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <a href="/superadmin/users" className="p-2 hover:bg-gray-100 rounded-lg transition">
                            <ArrowLeft className="h-5 w-5" />
                        </a>
                        <div>
                            <h1 className="text-3xl font-bold text-gray-900">Create New User</h1>
                            <p className="text-gray-500 mt-1">Add a new user to the platform</p>
                        </div>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 className="text-lg font-bold text-gray-900 mb-6">Basic Information</h2>
                        
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Full Name <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={e => setData((prev) => ({ ...prev, name: e.target.value }))}
                                    className={`w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent ${
                                        errors.name ? 'border-red-500' : 'border-gray-300'
                                    }`}
                                    placeholder="John Doe"
                                    required
                                />
                                {errors.name && (
                                    <p className="mt-1 text-sm text-red-600">{errors.name}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Email Address <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={e => setData((prev) => ({ ...prev, email: e.target.value }))}
                                    className={`w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent ${
                                        errors.email ? 'border-red-500' : 'border-gray-300'
                                    }`}
                                    placeholder="john@example.com"
                                    required
                                />
                                {errors.email && (
                                    <p className="mt-1 text-sm text-red-600">{errors.email}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Mobile Number
                                </label>
                                <input
                                    type="tel"
                                    value={data.mobile_number}
                                    onChange={e => setData((prev) => ({ ...prev, mobile_number: e.target.value }))}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="+44 7700 900000"
                                />
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Role <span className="text-red-500">*</span>
                                </label>
                                <select
                                    value={data.role}
                                    onChange={e => setData((prev) => ({ ...prev, role: e.target.value }))}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    required
                                >
                                    <option value="parent">Parent</option>
                                    <option value="teacher">Teacher</option>
                                    <option value="admin">Admin</option>
                                    <option value="super_admin">Super Admin</option>
                                </select>
                                {errors.role && (
                                    <p className="mt-1 text-sm text-red-600">{errors.role}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Organization
                                </label>
                                <select
                                    value={data.current_organization_id}
                                    onChange={e => setData((prev) => ({ ...prev, current_organization_id: e.target.value }))}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                >
                                    <option value="">Select an organization</option>
                                    {organizations?.map(org => (
                                        <option key={org.id} value={org.id}>
                                            {org.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.current_organization_id && (
                                    <p className="mt-1 text-sm text-red-600">{errors.current_organization_id}</p>
                                )}
                                <p className="mt-1 text-xs text-gray-500">Optional: Assign user to an organization</p>
                            </div>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h2 className="text-lg font-bold text-gray-900 mb-6">Security</h2>
                        
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Password <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="password"
                                    value={data.password}
                                    onChange={e => setData((prev) => ({ ...prev, password: e.target.value }))}
                                    className={`w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent ${
                                        errors.password ? 'border-red-500' : 'border-gray-300'
                                    }`}
                                    placeholder="••••••••"
                                    required
                                />
                                {errors.password && (
                                    <p className="mt-1 text-sm text-red-600">{errors.password}</p>
                                )}
                                <p className="mt-1 text-xs text-gray-500">Minimum 8 characters</p>
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-2">
                                    Confirm Password <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={e => setData((prev) => ({ ...prev, password_confirmation: e.target.value }))}
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    placeholder="••••••••"
                                    required
                                />
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center justify-end gap-4 pt-4">
                        <a
                            href="/superadmin/users"
                            className="flex items-center gap-2 px-6 py-2 border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-lg font-medium transition"
                        >
                            <X className="h-4 w-4" />
                            Cancel
                        </a>
                        <button
                            type="submit"
                            disabled={processing}
                            className="flex items-center gap-2 px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <Save className="h-4 w-4" />
                            {processing ? 'Creating...' : 'Create User'}
                        </button>
                    </div>
                </form>
            </div>
        </SuperAdminLayout>
    );
}
