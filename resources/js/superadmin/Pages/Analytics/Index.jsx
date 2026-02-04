import React, { useEffect, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    TrendingUp, Users, DollarSign, BookOpen, 
    Calendar, Download, ArrowUp, ArrowDown 
} from 'lucide-react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';

export default function Analytics() {
    const { showError } = useToast();
    const [timeRange, setTimeRange] = useState('30days');
    const [loading, setLoading] = useState(true);
    const [analytics, setAnalytics] = useState(null);

    useEffect(() => {
        let mounted = true;

        const loadAnalytics = async () => {
            try {
                setLoading(true);
                const response = await apiClient.get('/superadmin/analytics/dashboard', { useToken: true });
                if (!mounted) return;
                setAnalytics(response?.data || null);
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load analytics.');
            } finally {
                if (mounted) setLoading(false);
            }
        };

        loadAnalytics();

        return () => {
            mounted = false;
        };
    }, [showError]);

    const stats = {
        totalUsers: analytics?.users?.total || 0,
        activeUsers: analytics?.users?.total || 0,
        revenue: 0,
        completedLessons: analytics?.content?.lessons || 0,
        userGrowth: 0,
        revenueGrowth: 0,
    };

    const userActivity = analytics?.users?.new_users_last_7_days || [];

    if (loading) {
        return (
            <SuperAdminLayout>
                <div className="text-gray-600">Loading analytics...</div>
            </SuperAdminLayout>
        );
    }

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Analytics Dashboard</h1>
                        <p className="text-gray-500 mt-1">Platform performance and insights</p>
                    </div>
                    <div className="flex gap-3">
                        <select
                            value={timeRange}
                            onChange={(e) => setTimeRange(e.target.value)}
                            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="7days">Last 7 Days</option>
                            <option value="30days">Last 30 Days</option>
                            <option value="90days">Last 90 Days</option>
                            <option value="1year">Last Year</option>
                        </select>
                        <button className="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                            <Download className="h-4 w-4" />
                            Export Report
                        </button>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div className="p-3 bg-blue-100 rounded-lg">
                                <Users className="h-6 w-6 text-blue-600" />
                            </div>
                            <span className="flex items-center gap-1 text-sm font-medium text-gray-500">
                                <ArrowUp className="h-4 w-4" />
                                0%
                            </span>
                        </div>
                        <div className="mt-4">
                            <h3 className="text-2xl font-bold text-gray-900">{stats.totalUsers.toLocaleString()}</h3>
                            <p className="text-sm text-gray-500">Total Users</p>
                            <p className="text-xs text-gray-400 mt-1">{stats.activeUsers} active</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div className="p-3 bg-green-100 rounded-lg">
                                <DollarSign className="h-6 w-6 text-green-600" />
                            </div>
                            <span className="flex items-center gap-1 text-sm font-medium text-gray-500">
                                <ArrowUp className="h-4 w-4" />
                                0%
                            </span>
                        </div>
                        <div className="mt-4">
                            <h3 className="text-2xl font-bold text-gray-900">£{stats.revenue.toLocaleString()}</h3>
                            <p className="text-sm text-gray-500">Total Revenue</p>
                            <p className="text-xs text-gray-400 mt-1">This period</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div className="p-3 bg-purple-100 rounded-lg">
                                <BookOpen className="h-6 w-6 text-purple-600" />
                            </div>
                            <span className="flex items-center gap-1 text-sm font-medium text-gray-500">
                                <TrendingUp className="h-4 w-4" />
                                0%
                            </span>
                        </div>
                        <div className="mt-4">
                            <h3 className="text-2xl font-bold text-gray-900">{stats.completedLessons.toLocaleString()}</h3>
                            <p className="text-sm text-gray-500">Lessons</p>
                            <p className="text-xs text-gray-400 mt-1">Total lessons</p>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div className="p-3 bg-orange-100 rounded-lg">
                                <Calendar className="h-6 w-6 text-orange-600" />
                            </div>
                            <span className="flex items-center gap-1 text-sm font-medium text-gray-500">
                                <ArrowUp className="h-4 w-4" />
                                0%
                            </span>
                        </div>
                        <div className="mt-4">
                            <h3 className="text-2xl font-bold text-gray-900">{analytics?.engagement?.live_sessions || 0}</h3>
                            <p className="text-sm text-gray-500">Live Sessions</p>
                            <p className="text-xs text-gray-400 mt-1">Total sessions</p>
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div className="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between mb-6">
                            <h3 className="text-lg font-bold text-gray-900">New Users (Last 7 Days)</h3>
                            <select className="px-3 py-1 text-sm border border-gray-300 rounded-lg">
                                <option>Daily</option>
                            </select>
                        </div>

                        <div className="space-y-4">
                            {userActivity.map((day, index) => (
                                <div key={index}>
                                    <div className="flex items-center justify-between text-sm mb-1">
                                        <span className="text-gray-600">{new Date(day.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</span>
                                        <span className="font-medium text-gray-900">{day.count} users</span>
                                    </div>
                                    <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div 
                                            className="h-full bg-gradient-to-r from-blue-500 to-purple-600 rounded-full transition-all"
                                            style={{ width: `${Math.min(100, (day.count / 50) * 100)}%` }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-bold text-gray-900 mb-6">Content Totals</h3>
                        <div className="space-y-4">
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm text-gray-600">Courses</span>
                                    <span className="text-sm font-medium text-gray-900">{analytics?.content?.courses || 0}</span>
                                </div>
                                <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div className="h-full bg-green-500 rounded-full" style={{ width: '60%' }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm text-gray-600">Lessons</span>
                                    <span className="text-sm font-medium text-gray-900">{analytics?.content?.lessons || 0}</span>
                                </div>
                                <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div className="h-full bg-blue-500 rounded-full" style={{ width: '40%' }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm text-gray-600">Assessments</span>
                                    <span className="text-sm font-medium text-gray-900">{analytics?.content?.assessments || 0}</span>
                                </div>
                                <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div className="h-full bg-purple-500 rounded-full" style={{ width: '30%' }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm text-gray-600">Services</span>
                                    <span className="text-sm font-medium text-gray-900">{analytics?.content?.services || 0}</span>
                                </div>
                                <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div className="h-full bg-orange-500 rounded-full" style={{ width: '20%' }} />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
