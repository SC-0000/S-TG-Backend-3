import React, { useEffect, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { ArrowLeft, Save, X } from 'lucide-react';
import { apiClient, extractValidationErrors } from '@/api';
import { useToast } from '@/contexts/ToastContext';

const resolveUserId = () => {
    const parts = window.location.pathname.split('/').filter(Boolean);
    const index = parts.indexOf('users');
    if (index >= 0 && parts[index + 1]) return parts[index + 1];
    return parts[parts.length - 1];
};

export default function EditUser() {
    const { showError, showSuccess } = useToast();
    const userId = resolveUserId();
    const [organizations, setOrganizations] = useState([]);
    const [loading, setLoading] = useState(true);
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const [data, setData] = useState({
        name: '',
        email: '',
        role: 'parent',
        status: 'active',
        address_line1: '',
        address_line2: '',
        mobile_number: '',
        current_organization_id: '',
        password: '',
        password_confirmation: '',
    });

    useEffect(() => {
        let mounted = true;

        const loadUser = async () => {
            try {
                setLoading(true);
                const [userResponse, orgResponse] = await Promise.all([
                    apiClient.get(`/superadmin/users/${userId}`, { useToken: true }),
                    apiClient.get('/superadmin/organizations', { params: { per_page: 200 }, useToken: true }),
                ]);
                if (!mounted) return;
                const user = userResponse?.data?.user;
                if (!user) throw new Error('User not found.');
                setOrganizations(Array.isArray(orgResponse?.data) ? orgResponse.data : []);
                setData((prev) => ({
                    ...prev,
                    name: user.name || '',
                    email: user.email || '',
                    role: user.role || 'parent',
                    current_organization_id: user.current_organization_id || '',
                }));
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load user.');
            } finally {
                if (mounted) setLoading(false);
            }
        };

        loadUser();

        return () => {
            mounted = false;
        };
    }, [showError, userId]);

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            const payload = {
                name: data.name,
                email: data.email,
                role: data.role,
                current_organization_id: data.current_organization_id || null,
            };

            await apiClient.put(`/superadmin/users/${userId}`, payload, { useToken: true });
            showSuccess('User updated.');
            window.location.href = '/superadmin/users';
        } catch (error) {
            const fieldErrors = extractValidationErrors(error);
            setErrors(fieldErrors);
            showError(error.message || 'Unable to update user.');
        } finally {
            setProcessing(false);
        }
    };

    if (loading) {
        return (
            <SuperAdminLayout>
                <div className="text-gray-600">Loading user...</div>
            </SuperAdminLayout>
        );
    }

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <a href="/superadmin/users" className="p-2 hover:bg-gray-100 rounded-lg transition">
                            <ArrowLeft className="h-5 w-5" />
                        </a>
                        <div>
                            <h1 className="text-3xl font-bold text-gray-900">Edit User</h1>
                            <p className="text-gray-500 mt-1">Update user information</p>
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
                                    required
                                />
                                {errors.email && (
                                    <p className="mt-1 text-sm text-red-600">{errors.email}</p>
                                )}
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
                            {processing ? 'Saving...' : 'Save Changes'}
                        </button>
                    </div>
                </form>
            </div>
        </SuperAdminLayout>
    );
}
