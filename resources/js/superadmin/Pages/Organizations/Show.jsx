import React, { useEffect, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    Building2, Users, FileText, Settings, TrendingUp, 
    Activity, Edit, ArrowLeft, Palette 
} from 'lucide-react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';

const resolveOrganizationId = () => {
    const parts = window.location.pathname.split('/').filter(Boolean);
    const index = parts.indexOf('organizations');
    if (index >= 0 && parts[index + 1]) return parts[index + 1];
    return parts[parts.length - 1];
};

export default function OrganizationShow() {
    const { showError } = useToast();
    const organizationId = resolveOrganizationId();
    const [loading, setLoading] = useState(true);
    const [organization, setOrganization] = useState(null);
    const [recentActivities, setRecentActivities] = useState([]);

    useEffect(() => {
        let mounted = true;

        const loadOrganization = async () => {
            try {
                setLoading(true);
                const response = await apiClient.get(`/superadmin/organizations/${organizationId}`, { useToken: true });
                if (!mounted) return;
                setOrganization(response?.data?.organization || null);
                setRecentActivities(response?.data?.recent_activities || []);
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load organization.');
            } finally {
                if (mounted) setLoading(false);
            }
        };

        loadOrganization();

        return () => {
            mounted = false;
        };
    }, [showError, organizationId]);

    const getActivityIcon = (type) => {
        switch (type) {
            case 'user_joined':
                return <Users className="h-5 w-5 text-blue-600" />;
            case 'course_created':
                return <FileText className="h-5 w-5 text-purple-600" />;
            case 'lesson_created':
                return <FileText className="h-5 w-5 text-green-600" />;
            case 'session_scheduled':
                return <Activity className="h-5 w-5 text-indigo-600" />;
            case 'assessment_created':
                return <TrendingUp className="h-5 w-5 text-orange-600" />;
            default:
                return <Activity className="h-5 w-5 text-gray-500" />;
        }
    };

    const formatRelativeTime = (timestamp) => {
        if (!timestamp) return 'Unknown';
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;
        const seconds = Math.floor(diff / 1000);
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);

        if (days > 0) return `${days} day${days > 1 ? 's' : ''} ago`;
        if (hours > 0) return `${hours} hour${hours > 1 ? 's' : ''} ago`;
        if (minutes > 0) return `${minutes} minute${minutes > 1 ? 's' : ''} ago`;
        return 'Just now';
    };

    if (loading) {
        return (
            <SuperAdminLayout>
                <div className="text-gray-600">Loading organization...</div>
            </SuperAdminLayout>
        );
    }

    if (!organization) {
        return (
            <SuperAdminLayout>
                <div className="text-red-600">Organization not found.</div>
            </SuperAdminLayout>
        );
    }

    const counts = organization.counts || {};

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
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
                                {organization.name}
                            </h1>
                            <p className="text-gray-500 mt-1">/{organization.slug}</p>
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <a
                            href={`/superadmin/organizations/${organization.id}/edit`}
                            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                        >
                            <Edit className="h-4 w-4" />
                            Edit
                        </a>
                        <a
                            href={`/superadmin/organizations/${organization.id}/settings`}
                            className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                        >
                            <Settings className="h-4 w-4" />
                            Settings
                        </a>
                    </div>
                </div>

                {/* Stats Grid */}
                <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-blue-100 rounded-lg">
                                <Users className="h-6 w-6 text-blue-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Users</p>
                                <p className="text-2xl font-bold text-gray-900">{counts.users || 0}</p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-purple-100 rounded-lg">
                                <FileText className="h-6 w-6 text-purple-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Courses</p>
                                <p className="text-2xl font-bold text-gray-900">{counts.courses || 0}</p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-green-100 rounded-lg">
                                <FileText className="h-6 w-6 text-green-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Content Lessons</p>
                                <p className="text-2xl font-bold text-gray-900">{counts.content_lessons || 0}</p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-indigo-100 rounded-lg">
                                <Activity className="h-6 w-6 text-indigo-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Live Sessions</p>
                                <p className="text-2xl font-bold text-gray-900">{counts.live_lesson_sessions || 0}</p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-orange-100 rounded-lg">
                                <TrendingUp className="h-6 w-6 text-orange-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Assessments</p>
                                <p className="text-2xl font-bold text-gray-900">{counts.assessments || 0}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Quick Links */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <a
                        href={`/superadmin/organizations/${organization.id}/users`}
                        className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition"
                    >
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">Manage Users</h3>
                                <p className="text-sm text-gray-500 mt-1">View and manage organization users</p>
                            </div>
                            <Users className="h-8 w-8 text-blue-600" />
                        </div>
                    </a>
                    <a
                        href={`/superadmin/organizations/${organization.id}/branding`}
                        className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition"
                    >
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">Branding</h3>
                                <p className="text-sm text-gray-500 mt-1">Customize logos, colors & themes</p>
                            </div>
                            <Palette className="h-8 w-8 text-pink-600" />
                        </div>
                    </a>
                    <a
                        href={`/superadmin/organizations/${organization.id}/content`}
                        className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition"
                    >
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">View Content</h3>
                                <p className="text-sm text-gray-500 mt-1">Browse organization content</p>
                            </div>
                            <FileText className="h-8 w-8 text-purple-600" />
                        </div>
                    </a>
                    <a
                        href={`/superadmin/organizations/${organization.id}/analytics`}
                        className="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-md transition"
                    >
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="text-lg font-semibold text-gray-900">Analytics</h3>
                                <p className="text-sm text-gray-500 mt-1">Organization performance metrics</p>
                            </div>
                            <TrendingUp className="h-8 w-8 text-green-600" />
                        </div>
                    </a>
                </div>

                {/* Recent Activity */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 className="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                        <Activity className="h-5 w-5" />
                        Recent Activity
                    </h2>
                    <div className="space-y-3">
                        {recentActivities && recentActivities.length > 0 ? (
                            recentActivities.map((activity, index) => (
                                <div key={index} className="flex items-center gap-4 py-3 border-b border-gray-100 last:border-0">
                                    <div className="h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center">
                                        {getActivityIcon(activity.type)}
                                    </div>
                                    <div className="flex-1">
                                        <p className="text-sm font-medium text-gray-900">{activity.description}</p>
                                        <p className="text-xs text-gray-500">{formatRelativeTime(activity.created_at)}</p>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <p className="text-sm text-gray-500 text-center py-8">No recent activity</p>
                        )}
                    </div>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
