import React, { useEffect, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    Search, Plus, Edit, Trash2, UserCheck, UserX, 
    Filter, Download, Mail, Shield 
} from 'lucide-react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';

export default function UserIndex() {
    const { showError, showSuccess } = useToast();
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedRole, setSelectedRole] = useState('all');
    const [selectedOrganization, setSelectedOrganization] = useState('all');
    const [users, setUsers] = useState([]);
    const [organizations, setOrganizations] = useState([]);
    const [pagination, setPagination] = useState({ current_page: 1, total_pages: 1 });
    const [loading, setLoading] = useState(true);

    const fetchUsers = async (page = 1) => {
        try {
            setLoading(true);
            const params = {
                page,
                search: searchTerm || undefined,
                ...(selectedRole !== 'all' ? { 'filter[role]': selectedRole } : {}),
                ...(selectedOrganization !== 'all' ? { organization_id: selectedOrganization } : {}),
            };
            const response = await apiClient.get('/superadmin/users', { params, useToken: true });
            setUsers(Array.isArray(response?.data) ? response.data : []);
            setOrganizations(response?.meta?.organizations || []);
            setPagination(response?.meta?.pagination || { current_page: 1, total_pages: 1 });
        } catch (error) {
            showError(error.message || 'Unable to load users.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        const timer = setTimeout(() => {
            fetchUsers(1);
        }, 300);

        return () => clearTimeout(timer);
    }, [searchTerm, selectedRole, selectedOrganization]);

    const handleDelete = async (userId) => {
        if (confirm('Are you sure you want to delete this user?')) {
            try {
                await apiClient.delete(`/superadmin/users/${userId}`, { useToken: true });
                setUsers((prev) => prev.filter((user) => user.id !== userId));
                showSuccess('User deleted.');
            } catch (error) {
                showError(error.message || 'Unable to delete user.');
            }
        }
    };

    const handleToggleStatus = async (userId) => {
        try {
            await apiClient.post(`/superadmin/users/${userId}/toggle-status`, null, { useToken: true });
            showSuccess('Status toggle requested.');
        } catch (error) {
            showError(error.message || 'Unable to toggle status.');
        }
    };

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">User Management</h1>
                        <p className="text-gray-500 mt-1">Manage all platform users</p>
                    </div>
                    <a
                        href="/superadmin/users/create"
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition"
                    >
                        <Plus className="h-5 w-5" />
                        Add User
                    </a>
                </div>

                {/* Filters & Search */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div className="md:col-span-2">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
                                <input
                                    type="text"
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    placeholder="Search by name or email..."
                                    className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                />
                            </div>
                        </div>

                        <select
                            value={selectedRole}
                            onChange={(e) => setSelectedRole(e.target.value)}
                            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="all">All Roles</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="admin">Admin</option>
                            <option value="teacher">Teacher</option>
                            <option value="parent">Parent</option>
                            <option value="guest_parent">Guest Parent</option>
                        </select>

                        <select
                            value={selectedOrganization}
                            onChange={(e) => setSelectedOrganization(e.target.value)}
                            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="all">All Organizations</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>
                                    {org.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex gap-3 mt-4 pt-4 border-t border-gray-200">
                        <button className="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition">
                            <Download className="h-4 w-4" />
                            Export CSV
                        </button>
                        <button className="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition">
                            <Mail className="h-4 w-4" />
                            Bulk Email
                        </button>
                    </div>
                </div>

                {/* Users Table */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    {loading ? (
                        <div className="p-6 text-center text-gray-600">Loading users...</div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-gray-50 border-b border-gray-200">
                                    <tr>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organization</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Joined</th>
                                        <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-200">
                                    {users.length > 0 ? (
                                        users.map((user) => (
                                            <tr key={user.id} className="hover:bg-gray-50 transition">
                                                <td className="px-6 py-4">
                                                    <div className="flex items-center gap-3">
                                                        <div className="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold">
                                                            {user.name?.charAt(0) || '?'}
                                                        </div>
                                                        <div>
                                                            <div className="text-sm font-medium text-gray-900">{user.name}</div>
                                                            <div className="text-sm text-gray-500">{user.email}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <span className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                        user.role === 'super_admin' ? 'bg-purple-100 text-purple-800' :
                                                        user.role === 'admin' ? 'bg-blue-100 text-blue-800' :
                                                        user.role === 'teacher' ? 'bg-green-100 text-green-800' :
                                                        'bg-gray-100 text-gray-800'
                                                    }`}>
                                                        {user.role === 'super_admin' && <Shield className="h-3 w-3" />}
                                                        {user.role?.replace('_', ' ') || '—'}
                                                    </span>
                                                </td>
                                                <td className="px-6 py-4 text-sm text-gray-700">
                                                    {user.current_organization?.name
                                                        || user.organizations?.find((org) => org.id === user.current_organization_id)?.name
                                                        || user.organizations?.[0]?.name
                                                        || '—'}
                                                </td>
                                                <td className="px-6 py-4">
                                                    <button
                                                        onClick={() => handleToggleStatus(user.id)}
                                                        className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium transition bg-green-100 text-green-800 hover:bg-green-200"
                                                    >
                                                        <UserCheck className="h-3 w-3" />
                                                        active
                                                    </button>
                                                </td>
                                                <td className="px-6 py-4 text-sm text-gray-500">
                                                    {user.created_at ? new Date(user.created_at).toLocaleDateString() : '—'}
                                                </td>
                                                <td className="px-6 py-4 text-right text-sm font-medium">
                                                    <div className="flex items-center justify-end gap-2">
                                                        <a href={`/superadmin/users/${user.id}`} className="text-blue-600 hover:text-blue-900 transition">View</a>
                                                        <a href={`/superadmin/users/${user.id}/edit`} className="text-gray-600 hover:text-gray-900 transition">
                                                            <Edit className="h-4 w-4" />
                                                        </a>
                                                        <button onClick={() => handleDelete(user.id)} className="text-red-600 hover:text-red-900 transition">
                                                            <Trash2 className="h-4 w-4" />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan="6" className="px-6 py-12 text-center text-gray-500">No users found</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {pagination.total_pages > 1 && (
                        <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                            <div className="text-sm text-gray-500">
                                Page {pagination.current_page} of {pagination.total_pages}
                            </div>
                            <div className="flex gap-2">
                                <button
                                    onClick={() => fetchUsers(pagination.current_page - 1)}
                                    disabled={pagination.current_page <= 1}
                                    className="px-3 py-1 rounded-lg text-sm font-medium bg-gray-100 hover:bg-gray-200 text-gray-700 disabled:opacity-50"
                                >
                                    Prev
                                </button>
                                <button
                                    onClick={() => fetchUsers(pagination.current_page + 1)}
                                    disabled={pagination.current_page >= pagination.total_pages}
                                    className="px-3 py-1 rounded-lg text-sm font-medium bg-gray-100 hover:bg-gray-200 text-gray-700 disabled:opacity-50"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </SuperAdminLayout>
    );
}
