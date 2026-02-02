import React from 'react';
import { Head, Link } from '@inertiajs/react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    ArrowLeft, Edit, Trash2, Mail, Phone, MapPin, 
    Calendar, Shield, CheckCircle, XCircle, Clock 
} from 'lucide-react';

export default function ShowUser({ user }) {
    const getRoleBadgeColor = (role) => {
        const colors = {
            super_admin: 'bg-purple-100 text-purple-800',
            admin: 'bg-blue-100 text-blue-800',
            teacher: 'bg-green-100 text-green-800',
            parent: 'bg-orange-100 text-orange-800',
            guest_parent: 'bg-gray-100 text-gray-800',
        };
        return colors[role] || 'bg-gray-100 text-gray-800';
    };

    const formatDate = (date) => {
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    };

    return (
        <SuperAdminLayout>
            <Head title={user.name} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-4">
                        <Link
                            href="/superadmin/users"
                            className="p-2 hover:bg-gray-100 rounded-lg transition"
                        >
                            <ArrowLeft className="h-5 w-5" />
                        </Link>
                        <div>
                            <h1 className="text-3xl font-bold text-gray-900">User Details</h1>
                            <p className="text-gray-500 mt-1">View complete user information</p>
                        </div>
                    </div>
                    <div className="flex gap-3">
                        <Link
                            href={`/superadmin/users/${user.id}/edit`}
                            className="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition"
                        >
                            <Edit className="h-4 w-4" />
                            Edit User
                        </Link>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Main Info */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Profile Card */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <div className="flex items-start gap-6">
                                <div className="h-24 w-24 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center text-white text-3xl font-bold flex-shrink-0">
                                    {user.name.charAt(0)}
                                </div>
                                <div className="flex-1">
                                    <h2 className="text-2xl font-bold text-gray-900">{user.name}</h2>
                                    <div className="flex items-center gap-3 mt-2">
                                        <span className={`inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium ${getRoleBadgeColor(user.role)}`}>
                                            {user.role === 'super_admin' && <Shield className="h-3 w-3" />}
                                            {user.role.replace('_', ' ').charAt(0).toUpperCase() + user.role.replace('_', ' ').slice(1)}
                                        </span>
                                        {user.status === 'active' ? (
                                            <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                                <CheckCircle className="h-3 w-3" />
                                                Active
                                            </span>
                                        ) : (
                                            <span className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                                                <XCircle className="h-3 w-3" />
                                                Inactive
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Contact Information */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-bold text-gray-900 mb-4">Contact Information</h3>
                            <div className="space-y-4">
                                <div className="flex items-center gap-3">
                                    <Mail className="h-5 w-5 text-gray-400" />
                                    <div>
                                        <p className="text-sm text-gray-500">Email Address</p>
                                        <p className="text-sm font-medium text-gray-900">{user.email}</p>
                                    </div>
                                </div>
                                {user.mobile_number && (
                                    <div className="flex items-center gap-3">
                                        <Phone className="h-5 w-5 text-gray-400" />
                                        <div>
                                            <p className="text-sm text-gray-500">Mobile Number</p>
                                            <p className="text-sm font-medium text-gray-900">{user.mobile_number}</p>
                                        </div>
                                    </div>
                                )}
                                {(user.address_line1 || user.address_line2) && (
                                    <div className="flex items-start gap-3">
                                        <MapPin className="h-5 w-5 text-gray-400 mt-0.5" />
                                        <div>
                                            <p className="text-sm text-gray-500">Address</p>
                                            <p className="text-sm font-medium text-gray-900">
                                                {user.address_line1 && <>{user.address_line1}<br /></>}
                                                {user.address_line2}
                                            </p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Account Activity */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-bold text-gray-900 mb-4">Account Activity</h3>
                            <div className="space-y-4">
                                <div className="flex items-center gap-3">
                                    <Calendar className="h-5 w-5 text-gray-400" />
                                    <div>
                                        <p className="text-sm text-gray-500">Member Since</p>
                                        <p className="text-sm font-medium text-gray-900">{formatDate(user.created_at)}</p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-3">
                                    <Clock className="h-5 w-5 text-gray-400" />
                                    <div>
                                        <p className="text-sm text-gray-500">Last Updated</p>
                                        <p className="text-sm font-medium text-gray-900">{formatDate(user.updated_at)}</p>
                                    </div>
                                </div>
                                {user.email_verified_at && (
                                    <div className="flex items-center gap-3">
                                        <CheckCircle className="h-5 w-5 text-green-500" />
                                        <div>
                                            <p className="text-sm text-gray-500">Email Verified</p>
                                            <p className="text-sm font-medium text-gray-900">{formatDate(user.email_verified_at)}</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Sidebar */}
                    <div className="space-y-6">
                        {/* Quick Actions */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-bold text-gray-900 mb-4">Quick Actions</h3>
                            <div className="space-y-2">
                                <Link
                                    href={`/superadmin/users/${user.id}/edit`}
                                    className="flex items-center gap-2 w-full px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition"
                                >
                                    <Edit className="h-4 w-4" />
                                    Edit User
                                </Link>
                                <button className="flex items-center gap-2 w-full px-4 py-2 text-sm text-red-700 hover:bg-red-50 rounded-lg transition">
                                    <Trash2 className="h-4 w-4" />
                                    Delete User
                                </button>
                            </div>
                        </div>

                        {/* Statistics */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-bold text-gray-900 mb-4">Statistics</h3>
                            <div className="space-y-4">
                                <div>
                                    <div className="flex items-center justify-between mb-1">
                                        <span className="text-sm text-gray-600">Profile Completion</span>
                                        <span className="text-sm font-medium text-gray-900">85%</span>
                                    </div>
                                    <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div className="h-full bg-blue-600 rounded-full" style={{ width: '85%' }}></div>
                                    </div>
                                </div>
                                <div className="pt-4 border-t border-gray-200">
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm text-gray-600">Total Logins</span>
                                        <span className="text-sm font-medium text-gray-900">42</span>
                                    </div>
                                </div>
                                <div>
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm text-gray-600">Last Login</span>
                                        <span className="text-sm font-medium text-gray-900">Today</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Additional Info */}
                        <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                            <h3 className="text-lg font-bold text-gray-900 mb-4">Additional Info</h3>
                            <div className="space-y-3 text-sm">
                                <div>
                                    <span className="text-gray-500">User ID:</span>
                                    <span className="ml-2 font-medium text-gray-900">{user.id}</span>
                                </div>
                                {user.billing_customer_id && (
                                    <div>
                                        <span className="text-gray-500">Billing ID:</span>
                                        <span className="ml-2 font-mono text-xs text-gray-900">{user.billing_customer_id}</span>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
