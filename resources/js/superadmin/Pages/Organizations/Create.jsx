import React, { useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { Building2, ArrowLeft } from 'lucide-react';
import { apiClient, extractValidationErrors } from '@/api';
import { useToast } from '@/contexts/ToastContext';

export default function OrganizationCreate() {
    const { showError, showSuccess } = useToast();
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});
    const [formData, setFormData] = useState({
        name: '',
        slug: '',
        status: 'active',
    });

    const handleSubmit = async (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        try {
            await apiClient.post('/superadmin/organizations', formData, { useToken: true });
            showSuccess('Organization created.');
            window.location.href = '/superadmin/organizations';
        } catch (error) {
            const fieldErrors = extractValidationErrors(error);
            setErrors(fieldErrors);
            showError(error.message || 'Unable to create organization.');
        } finally {
            setProcessing(false);
        }
    };

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                <div className="flex items-center gap-4">
                    <a
                        href="/superadmin/organizations"
                        className="p-2 hover:bg-gray-100 rounded-lg transition"
                    >
                        <ArrowLeft className="h-5 w-5" />
                    </a>
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-2">
                            <Building2 className="h-8 w-8 text-purple-600" />
                            Create Organization
                        </h1>
                        <p className="text-gray-500 mt-1">Add a new organization to the platform</p>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <form onSubmit={handleSubmit} className="space-y-6">
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Organization Name
                            </label>
                            <input
                                type="text"
                                value={formData.name}
                                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                className={`w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 ${
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
                                Slug
                            </label>
                            <input
                                type="text"
                                value={formData.slug}
                                onChange={(e) => setFormData({ ...formData, slug: e.target.value })}
                                className={`w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-500 ${
                                    errors.slug ? 'border-red-500' : 'border-gray-300'
                                }`}
                            />
                            {errors.slug && (
                                <p className="mt-1 text-sm text-red-600">{errors.slug}</p>
                            )}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-2">
                                Status
                            </label>
                            <select
                                value={formData.status}
                                onChange={(e) => setFormData({ ...formData, status: e.target.value })}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                        </div>

                        <div className="flex gap-3">
                            <button
                                type="submit"
                                disabled={processing}
                                className="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition disabled:opacity-50"
                            >
                                {processing ? 'Creating...' : 'Create Organization'}
                            </button>
                            <a
                                href="/superadmin/organizations"
                                className="px-6 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                            >
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
