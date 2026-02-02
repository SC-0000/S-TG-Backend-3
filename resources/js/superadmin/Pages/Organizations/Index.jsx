import React, { useEffect, useMemo, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    Building2, Plus, Search, Filter, MoreVertical, 
    Users, Eye, Edit, Trash2, CheckCircle, XCircle, Palette 
} from 'lucide-react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';

export default function OrganizationsIndex() {
    const { showError, showSuccess } = useToast();
    const [searchTerm, setSearchTerm] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [organizations, setOrganizations] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let mounted = true;
        const timer = setTimeout(async () => {
            try {
                setLoading(true);
                const params = {};
                if (searchTerm) params.search = searchTerm;
                if (statusFilter !== 'all') params['filter[status]'] = statusFilter;
                const response = await apiClient.get('/superadmin/organizations', { params, useToken: true });
                if (!mounted) return;
                setOrganizations(Array.isArray(response?.data) ? response.data : []);
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load organizations.');
            } finally {
                if (mounted) setLoading(false);
            }
        }, 300);

        return () => {
            mounted = false;
            clearTimeout(timer);
        };
    }, [searchTerm, statusFilter, showError]);

    const handleDelete = async (orgId) => {
        if (!confirm('Delete this organization?')) return;
        try {
            await apiClient.delete(`/superadmin/organizations/${orgId}`, { useToken: true });
            setOrganizations((prev) => prev.filter((org) => org.id !== orgId));
            showSuccess('Organization deleted.');
        } catch (error) {
            showError(error.message || 'Unable to delete organization.');
        }
    };

    const stats = useMemo(() => {
        return {
            total: organizations.length,
            active: organizations.filter(o => o.status === 'active').length,
            suspended: organizations.filter(o => o.status === 'suspended').length,
            users: organizations.reduce((sum, org) => sum + (org.users_count || 0), 0),
        };
    }, [organizations]);

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-2">
                            <Building2 className="h-8 w-8 text-purple-600" />
                            Organizations
                        </h1>
                        <p className="text-gray-500 mt-1">Manage all organizations on the platform</p>
                    </div>
                    <a
                        href="/superadmin/organizations/create"
                        className="flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition"
                    >
                        <Plus className="h-5 w-5" />
                        Create Organization
                    </a>
                </div>

                {/* Stats Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-blue-100 rounded-lg">
                                <Building2 className="h-6 w-6 text-blue-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Total Organizations</p>
                                <p className="text-2xl font-bold text-gray-900">{stats.total}</p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-green-100 rounded-lg">
                                <CheckCircle className="h-6 w-6 text-green-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Active</p>
                                <p className="text-2xl font-bold text-gray-900">{stats.active}</p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-red-100 rounded-lg">
                                <XCircle className="h-6 w-6 text-red-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Suspended</p>
                                <p className="text-2xl font-bold text-gray-900">{stats.suspended}</p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-purple-100 rounded-lg">
                                <Users className="h-6 w-6 text-purple-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Total Users</p>
                                <p className="text-2xl font-bold text-gray-900">{stats.users}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Filters */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div className="flex flex-col md:flex-row gap-4">
                        <div className="flex-1 relative">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
                            <input
                                type="text"
                                placeholder="Search organizations..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                            />
                        </div>
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="w-full md:w-48 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                        >
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>

                {/* Table */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    {loading ? (
                        <div className="p-6 text-center text-gray-600">Loading organizations...</div>
                    ) : (
                        <table className="min-w-full">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Organization</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Users</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {organizations.map((org) => (
                                    <tr key={org.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-3">
                                                <div className="h-10 w-10 bg-purple-100 rounded-lg flex items-center justify-center">
                                                    <Building2 className="h-5 w-5 text-purple-600" />
                                                </div>
                                                <div>
                                                    <p className="text-sm font-medium text-gray-900">{org.name}</p>
                                                    <p className="text-sm text-gray-500">{org.slug}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-900">{org.users_count || 0}</td>
                                        <td className="px-6 py-4">
                                            <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                                                org.status === 'active' ? 'bg-green-100 text-green-800' :
                                                org.status === 'suspended' ? 'bg-red-100 text-red-800' :
                                                'bg-gray-100 text-gray-800'
                                            }`}>
                                                {org.status}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-500">{org.created_at?.slice(0, 10)}</td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <a href={`/superadmin/organizations/${org.id}`} className="text-blue-600 hover:text-blue-700">
                                                    <Eye className="h-4 w-4" />
                                                </a>
                                                <a href={`/superadmin/organizations/${org.id}/edit`} className="text-gray-600 hover:text-gray-700">
                                                    <Edit className="h-4 w-4" />
                                                </a>
                                                <a href={`/superadmin/organizations/${org.id}/branding`} className="text-purple-600 hover:text-purple-700">
                                                    <Palette className="h-4 w-4" />
                                                </a>
                                                <button
                                                    type="button"
                                                    onClick={() => handleDelete(org.id)}
                                                    className="text-red-600 hover:text-red-700"
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>
        </SuperAdminLayout>
    );
}
