import React, { useState } from 'react';
import { Head } from '@inertiajs/react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    TrendingUp, Users, DollarSign, BookOpen, 
    Calendar, Download, Filter, ArrowUp, ArrowDown 
} from 'lucide-react';

export default function Analytics({ analytics }) {
    const [timeRange, setTimeRange] = useState('30days');

    // Sample data - will come from backend
    const stats = {
        totalUsers: analytics?.totalUsers || 1234,
        activeUsers: analytics?.activeUsers || 856,
        revenue: analytics?.revenue || 45678,
        completedLessons: analytics?.completedLessons || 3456,
        userGrowth: analytics?.userGrowth || 12.5,
        revenueGrowth: analytics?.revenueGrowth || 8.3,
    };

    const topCourses = analytics?.topCourses || [
        { id: 1, title: '11+ English Comprehension', enrollments: 234, revenue: 12450 },
        { id: 2, title: 'Mathematics Mastery', enrollments: 198, revenue: 10890 },
        { id: 3, title: 'Science Fundamentals', enrollments: 176, revenue: 9680 },
        { id: 4, title: 'Creative Writing Workshop', enrollments: 145, revenue: 7975 },
        { id: 5, title: 'Problem Solving Skills', enrollments: 132, revenue: 7260 },
    ];

    const userActivity = analytics?.userActivity || [
        { date: '2025-02-01', users: 120 },
        { date: '2025-02-02', users: 135 },
        { date: '2025-02-03', users: 142 },
        { date: '2025-02-04', users: 128 },
        { date: '2025-02-05', users: 156 },
        { date: '2025-02-06', users: 168 },
        { date: '2025-02-07', users: 175 },
    ];

    return (
        <SuperAdminLayout>
            <Head title="Analytics" />

            <div className="space-y-6">
                {/* Header */}
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

                {/* Key Metrics */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    {/* Total Users */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div className="p-3 bg-blue-100 rounded-lg">
                                <Users className="h-6 w-6 text-blue-600" />
                            </div>
                            <span className={`flex items-center gap-1 text-sm font-medium ${stats.userGrowth >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                {stats.userGrowth >= 0 ? <ArrowUp className="h-4 w-4" /> : <ArrowDown className="h-4 w-4" />}
                                {Math.abs(stats.userGrowth)}%
                            </span>
                        </div>
                        <div className="mt-4">
                            <h3 className="text-2xl font-bold text-gray-900">{stats.totalUsers.toLocaleString()}</h3>
                            <p className="text-sm text-gray-500">Total Users</p>
                            <p className="text-xs text-gray-400 mt-1">{stats.activeUsers} active</p>
                        </div>
                    </div>

                    {/* Revenue */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div className="p-3 bg-green-100 rounded-lg">
                                <DollarSign className="h-6 w-6 text-green-600" />
                            </div>
                            <span className={`flex items-center gap-1 text-sm font-medium ${stats.revenueGrowth >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                {stats.revenueGrowth >= 0 ? <ArrowUp className="h-4 w-4" /> : <ArrowDown className="h-4 w-4" />}
                                {Math.abs(stats.revenueGrowth)}%
                            </span>
                        </div>
                        <div className="mt-4">
                            <h3 className="text-2xl font-bold text-gray-900">£{stats.revenue.toLocaleString()}</h3>
                            <p className="text-sm text-gray-500">Total Revenue</p>
                            <p className="text-xs text-gray-400 mt-1">This period</p>
                        </div>
                    </div>

                    {/* Completed Lessons */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div className="p-3 bg-purple-100 rounded-lg">
                                <BookOpen className="h-6 w-6 text-purple-600" />
                            </div>
                            <span className="flex items-center gap-1 text-sm font-medium text-green-600">
                                <TrendingUp className="h-4 w-4" />
                                15.2%
                            </span>
                        </div>
                        <div className="mt-4">
                            <h3 className="text-2xl font-bold text-gray-900">{stats.completedLessons.toLocaleString()}</h3>
                            <p className="text-sm text-gray-500">Lessons Completed</p>
                            <p className="text-xs text-gray-400 mt-1">Total completions</p>
                        </div>
                    </div>

                    {/* Engagement Rate */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div className="p-3 bg-orange-100 rounded-lg">
                                <Calendar className="h-6 w-6 text-orange-600" />
                            </div>
                            <span className="flex items-center gap-1 text-sm font-medium text-green-600">
                                <ArrowUp className="h-4 w-4" />
                                5.8%
                            </span>
                        </div>
                        <div className="mt-4">
                            <h3 className="text-2xl font-bold text-gray-900">69.4%</h3>
                            <p className="text-sm text-gray-500">Engagement Rate</p>
                            <p className="text-xs text-gray-400 mt-1">Daily active users</p>
                        </div>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* User Activity Chart */}
                    <div className="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between mb-6">
                            <h3 className="text-lg font-bold text-gray-900">User Activity</h3>
                            <select className="px-3 py-1 text-sm border border-gray-300 rounded-lg">
                                <option>Daily</option>
                                <option>Weekly</option>
                                <option>Monthly</option>
                            </select>
                        </div>
                        
                        {/* Simple Bar Chart Visualization */}
                        <div className="space-y-4">
                            {userActivity.map((day, index) => (
                                <div key={index}>
                                    <div className="flex items-center justify-between text-sm mb-1">
                                        <span className="text-gray-600">{new Date(day.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}</span>
                                        <span className="font-medium text-gray-900">{day.users} users</span>
                                    </div>
                                    <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                        <div 
                                            className="h-full bg-gradient-to-r from-blue-500 to-purple-600 rounded-full transition-all"
                                            style={{ width: `${(day.users / 200) * 100}%` }}
                                        />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* Revenue Breakdown */}
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <h3 className="text-lg font-bold text-gray-900 mb-6">Revenue Breakdown</h3>
                        <div className="space-y-4">
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm text-gray-600">Subscriptions</span>
                                    <span className="text-sm font-medium text-gray-900">£28,450</span>
                                </div>
                                <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div className="h-full bg-green-500 rounded-full" style={{ width: '62%' }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm text-gray-600">Courses</span>
                                    <span className="text-sm font-medium text-gray-900">£12,340</span>
                                </div>
                                <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div className="h-full bg-blue-500 rounded-full" style={{ width: '27%' }} />
                                </div>
                            </div>
                            <div>
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-sm text-gray-600">Services</span>
                                    <span className="text-sm font-medium text-gray-900">£4,888</span>
                                </div>
                                <div className="h-2 bg-gray-200 rounded-full overflow-hidden">
                                    <div className="h-full bg-purple-500 rounded-full" style={{ width: '11%' }} />
                                </div>
                            </div>
                        </div>
                        
                        <div className="mt-6 pt-6 border-t border-gray-200">
                            <div className="flex items-center justify-between">
                                <span className="text-sm font-medium text-gray-900">Total Revenue</span>
                                <span className="text-lg font-bold text-gray-900">£45,678</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Top Courses */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div className="px-6 py-4 border-b border-gray-200">
                        <h3 className="text-lg font-bold text-gray-900">Top Performing Courses</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full">
                            <thead className="bg-gray-50 border-b border-gray-200">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Rank</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Course Name</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Enrollments</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Revenue</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Trend</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {topCourses.map((course, index) => (
                                    <tr key={course.id} className="hover:bg-gray-50">
                                        <td className="px-6 py-4">
                                            <div className={`flex items-center justify-center h-8 w-8 rounded-full font-bold text-sm ${
                                                index === 0 ? 'bg-yellow-100 text-yellow-800' :
                                                index === 1 ? 'bg-gray-100 text-gray-800' :
                                                index === 2 ? 'bg-orange-100 text-orange-800' :
                                                'bg-gray-50 text-gray-600'
                                            }`}>
                                                {index + 1}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="text-sm font-medium text-gray-900">{course.title}</div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="text-sm text-gray-900">{course.enrollments}</div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="text-sm font-medium text-gray-900">£{course.revenue.toLocaleString()}</div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="flex items-center gap-1 text-sm font-medium text-green-600">
                                                <TrendingUp className="h-4 w-4" />
                                                +{Math.floor(Math.random() * 20 + 5)}%
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </SuperAdminLayout>
    );
}
