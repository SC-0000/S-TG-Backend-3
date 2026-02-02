import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    Search, Plus, Edit, Trash2, UserCheck, UserX, 
    Filter, Download, Mail, Shield, ChevronDown 
} from 'lucide-react';

export default function UserIndex({ users, organizations = [], filters = {} }) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '');
    const [selectedRole, setSelectedRole] = useState(filters.role || 'all');
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'all');
    const [selectedOrganization, setSelectedOrganization] = useState(filters.current_organization_id || 'all');

    const handleSearch = (e) => {
        e.preventDefault();
        router.get('/superadmin/users', {
            search: searchTerm,
            role: selectedRole !== 'all' ? selectedRole : undefined,
            status: selectedStatus !== 'all' ? selectedStatus : undefined,
            organization_id: selectedOrganization !== 'all' ? selectedOrganization : undefined,
        }, { preserveState: true });
    };

    const handleDelete = (userId) => {
        if (confirm('Are you sure you want to delete this user?')) {
            router.delete(`/superadmin/users/${userId}`);
        }
    };

    const handleToggleStatus = (userId, currentStatus) => {
        router.put(`/superadmin/users/${userId}/toggle-status`, {
            status: currentStatus === 'active' ? 'inactive' : 'active'
        });
    };

    return (
        <SuperAdminLayout>
            <Head title="User Management" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">User Management</h1>
                        <p className="text-gray-500 mt-1">Manage all platform users</p>
                    </div>
                    <Link
                        href="/superadmin/users/create"
                        className="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition"
                    >
                        <Plus className="h-5 w-5" />
                        Add User
                    </Link>
                </div>

                {/* Filters & Search */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <form onSubmit={handleSearch} className="grid grid-cols-1 md:grid-cols-5 gap-4">
                        {/* Search */}
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

                        {/* Role Filter */}
                        <select
                            value={selectedRole}
                            onChange={(e) => {
                                const value = e.target.value;
                                setSelectedRole(value);
                                router.get('/superadmin/users', {
                                    search: searchTerm,
                                    role: value !== 'all' ? value : undefined,
                                    status: selectedStatus !== 'all' ? selectedStatus : undefined,
                                    organization_id: selectedOrganization !== 'all' ? selectedOrganization : undefined,
                                }, { preserveState: true });
                            }}
                            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="all">All Roles</option>
                            <option value="super_admin">Super Admin</option>
                            <option value="admin">Admin</option>
                            <option value="teacher">Teacher</option>
                            <option value="parent">Parent</option>
                            <option value="guest_parent">Guest Parent</option>
                        </select>

                        {/* Status Filter */}
                        <select
                            value={selectedStatus}
                            onChange={(e) => {
                                const value = e.target.value;
                                setSelectedStatus(value);
                                router.get('/superadmin/users', {
                                    search: searchTerm,
                                    role: selectedRole !== 'all' ? selectedRole : undefined,
                                    status: value !== 'all' ? value : undefined,
                                    organization_id: selectedOrganization !== 'all' ? selectedOrganization : undefined,
                                }, { preserveState: true });
                            }}
                            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="all">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>

                        {/* Organization Filter */}
                        <select
                            value={selectedOrganization}
                            onChange={(e) => {
                                const value = e.target.value;
                                setSelectedOrganization(value);
                                router.get('/superadmin/users', {
                                    search: searchTerm,
                                    role: selectedRole !== 'all' ? selectedRole : undefined,
                                    status: selectedStatus !== 'all' ? selectedStatus : undefined,
                                    organization_id: value !== 'all' ? value : undefined,
                                }, { preserveState: true });
                            }}
                            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="all">All Organizations</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>
                                    {org.name}
                                </option>
                            ))}
                        </select>
                    </form>

                    {/* Quick Actions */}
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
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        User
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Role
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Org ID
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Status
                                    </th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Joined
                                    </th>
                                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {users.data && users.data.length > 0 ? (
                                    users.data.map((user) => (
                                        <tr key={user.id} className="hover:bg-gray-50 transition">
                                            <td className="px-6 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="h-10 w-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white font-bold">
                                                        {user.name.charAt(0)}
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
                                                    {user.role.replace('_', ' ')}
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
                                                    onClick={() => handleToggleStatus(user.id, user.status)}
                                                    className={`inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium transition ${
                                                        user.status === 'active' 
                                                            ? 'bg-green-100 text-green-800 hover:bg-green-200' 
                                                            : 'bg-red-100 text-red-800 hover:bg-red-200'
                                                    }`}
                                                >
                                                    {user.status === 'active' ? <UserCheck className="h-3 w-3" /> : <UserX className="h-3 w-3" />}
                                                    {user.status}
                                                </button>
                                            </td>
                                            <td className="px-6 py-4 text-sm text-gray-500">
                                                {new Date(user.created_at).toLocaleDateString()}
                                            </td>
                                            <td className="px-6 py-4 text-right text-sm font-medium">
                                                <div className="flex items-center justify-end gap-2">
                                                    <Link
                                                        href={`/superadmin/users/${user.id}`}
                                                        className="text-blue-600 hover:text-blue-900 transition"
                                                    >
                                                        View
                                                    </Link>
                                                    <Link
                                                        href={`/superadmin/users/${user.id}/edit`}
                                                        className="text-gray-600 hover:text-gray-900 transition"
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Link>
                                                    <button
                                                        onClick={() => handleDelete(user.id)}
                                                        className="text-red-600 hover:text-red-900 transition"
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td colSpan="5" className="px-6 py-12 text-center text-gray-500">
                                            No users found
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {users.links && users.links.length > 3 && (
                        <div className="px-6 py-4 border-t border-gray-200 flex items-center justify-between">
                            <div className="text-sm text-gray-500">
                                Showing {users.from} to {users.to} of {users.total} users
                            </div>
                            <div className="flex gap-2">
                                {users.links.map((link, index) => (
                                    <button
                                        key={index}
                                        onClick={() => link.url && router.get(link.url)}
                                        disabled={!link.url}
                                        className={`px-3 py-1 rounded-lg text-sm font-medium transition ${
                                            link.active
                                                ? 'bg-blue-600 text-white'
                                                : link.url
                                                ? 'bg-gray-100 hover:bg-gray-200 text-gray-700'
                                                : 'bg-gray-50 text-gray-400 cursor-not-allowed'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </SuperAdminLayout>
    );
}
