import React, { useEffect, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    Users, Building2, FileText,
    Activity, AlertCircle 
} from 'lucide-react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';

const StatCard = ({ title, value, change, icon: Icon, color }) => (
    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
        <div className="flex items-center justify-between mb-4">
            <div className={`p-3 rounded-lg ${color}`}>
                <Icon className="h-6 w-6 text-white" />
            </div>
            {change && (
                <span className={`text-sm font-medium ${change > 0 ? 'text-green-600' : 'text-red-600'}`}>
                    {change > 0 ? '+' : ''}{change}%
                </span>
            )}
        </div>
        <h3 className="text-gray-500 text-sm font-medium mb-1">{title}</h3>
        <p className="text-2xl font-bold text-gray-900">{value}</p>
    </div>
);

export default function Dashboard() {
    const { showError } = useToast();
    const [stats, setStats] = useState(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        let mounted = true;

        const loadStats = async () => {
            try {
                setLoading(true);
                const response = await apiClient.get('/superadmin/dashboard', { useToken: true });
                if (!mounted) return;
                setStats(response?.data?.stats || null);
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load dashboard stats.');
            } finally {
                if (mounted) {
                    setLoading(false);
                }
            }
        };

        loadStats();

        return () => {
            mounted = false;
        };
    }, [showError]);

    const statsData = stats ? [
        { 
            title: 'Total Users', 
            value: stats.total_users?.toLocaleString() || '0', 
            change: null, 
            icon: Users, 
            color: 'bg-blue-600' 
        },
        { 
            title: 'Organizations', 
            value: stats.total_organizations?.toLocaleString() || '0', 
            change: null, 
            icon: Building2, 
            color: 'bg-purple-600' 
        },
        { 
            title: 'Courses', 
            value: stats.total_courses?.toLocaleString() || '0', 
            change: null, 
            icon: FileText, 
            color: 'bg-green-600' 
        },
        { 
            title: 'Lessons', 
            value: stats.total_lessons?.toLocaleString() || '0', 
            change: null, 
            icon: FileText, 
            color: 'bg-purple-600' 
        },
    ] : [];

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Dashboard</h1>
                        <p className="text-gray-500 mt-1">Welcome to the Super Admin Control Center</p>
                    </div>
                </div>

                {/* Stats Grid */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    {loading ? (
                        <div className="col-span-full text-gray-500">Loading stats...</div>
                    ) : (
                        statsData.map((stat, index) => (
                            <StatCard key={index} {...stat} />
                        ))
                    )}
                </div>

                {/* Quick Actions */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 className="text-lg font-bold text-gray-900 mb-4">Quick Actions</h2>
                    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <button className="px-4 py-3 bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg font-medium transition">
                            Create User
                        </button>
                        <button className="px-4 py-3 bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg font-medium transition">
                            Add Organization
                        </button>
                        <button className="px-4 py-3 bg-green-50 hover:bg-green-100 text-green-700 rounded-lg font-medium transition">
                            Publish Content
                        </button>
                        <button className="px-4 py-3 bg-orange-50 hover:bg-orange-100 text-orange-700 rounded-lg font-medium transition">
                            View Reports
                        </button>
                    </div>
                </div>

                {/* Recent Activity */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <h2 className="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                        <Activity className="h-5 w-5" />
                        Recent Activity
                    </h2>
                    <div className="space-y-4">
                        {[1, 2, 3, 4, 5].map((item) => (
                            <div key={item} className="flex items-center gap-4 py-3 border-b border-gray-100 last:border-0">
                                <div className="h-10 w-10 rounded-full bg-gray-100 flex items-center justify-center">
                                    <AlertCircle className="h-5 w-5 text-gray-500" />
                                </div>
                                <div className="flex-1">
                                    <p className="text-sm font-medium text-gray-900">Activity Item {item}</p>
                                    <p className="text-xs text-gray-500">2 hours ago</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
