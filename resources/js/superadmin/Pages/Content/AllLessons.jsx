import React, { useEffect, useMemo, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { FileText, Search, Eye, Edit, Trash2, Building2, Users } from 'lucide-react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';

export default function AllLessons() {
    const { showError } = useToast();
    const [searchTerm, setSearchTerm] = useState('');
    const [orgFilter, setOrgFilter] = useState('all');
    const [statusFilter, setStatusFilter] = useState('all');
    const [lessons, setLessons] = useState([]);
    const [organizations, setOrganizations] = useState([]);
    const [loading, setLoading] = useState(true);

    const orgNameById = useMemo(() => {
        return new Map(organizations.map((org) => [org.id, org.name]));
    }, [organizations]);

    useEffect(() => {
        let mounted = true;

        const loadData = async () => {
            try {
                setLoading(true);
                const [lessonResponse, orgResponse] = await Promise.all([
                    apiClient.get('/superadmin/content/lessons', {
                        params: {
                            search: searchTerm || undefined,
                            ...(orgFilter !== 'all' ? { 'filter[organization_id]': orgFilter } : {}),
                            ...(statusFilter !== 'all' ? { 'filter[status]': statusFilter } : {}),
                        },
                        useToken: true,
                    }),
                    apiClient.get('/superadmin/organizations', { params: { per_page: 200 }, useToken: true }),
                ]);
                if (!mounted) return;
                setLessons(Array.isArray(lessonResponse?.data) ? lessonResponse.data : []);
                setOrganizations(Array.isArray(orgResponse?.data) ? orgResponse.data : []);
            } catch (error) {
                if (!mounted) return;
                showError(error.message || 'Unable to load lessons.');
            } finally {
                if (mounted) setLoading(false);
            }
        };

        const timer = setTimeout(loadData, 300);
        return () => {
            mounted = false;
            clearTimeout(timer);
        };
    }, [searchTerm, orgFilter, statusFilter, showError]);

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-2">
                            <FileText className="h-8 w-8 text-blue-600" />
                            All Lessons
                        </h1>
                        <p className="text-gray-500 mt-1">Platform-wide lesson management</p>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-blue-100 rounded-lg">
                                <FileText className="h-6 w-6 text-blue-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Total Lessons</p>
                                <p className="text-2xl font-bold text-gray-900">{lessons.length}</p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-purple-100 rounded-lg">
                                <FileText className="h-6 w-6 text-purple-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Slides</p>
                                <p className="text-2xl font-bold text-gray-900">
                                    {lessons.reduce((sum, l) => sum + (l.slides_count || 0), 0)}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-green-100 rounded-lg">
                                <FileText className="h-6 w-6 text-green-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Modules</p>
                                <p className="text-2xl font-bold text-gray-900">
                                    {lessons.reduce((sum, l) => sum + (l.modules_count || 0), 0)}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center gap-3">
                            <div className="p-3 bg-orange-100 rounded-lg">
                                <Users className="h-6 w-6 text-orange-600" />
                            </div>
                            <div>
                                <p className="text-sm text-gray-500">Assessments</p>
                                <p className="text-2xl font-bold text-gray-900">
                                    {lessons.reduce((sum, l) => sum + (l.assessments_count || 0), 0)}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div className="flex flex-col md:flex-row gap-4">
                        <div className="flex-1 relative">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
                            <input
                                type="text"
                                placeholder="Search lessons..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            />
                        </div>
                        <select
                            value={orgFilter}
                            onChange={(e) => setOrgFilter(e.target.value)}
                            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="all">All Organizations</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>{org.name}</option>
                            ))}
                        </select>
                        <select
                            value={statusFilter}
                            onChange={(e) => setStatusFilter(e.target.value)}
                            className="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        >
                            <option value="all">All Status</option>
                            <option value="draft">Draft</option>
                            <option value="published">Published</option>
                            <option value="live">Live</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                </div>

                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    {loading ? (
                        <div className="p-6 text-center text-gray-600">Loading lessons...</div>
                    ) : (
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lesson</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Organization</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Modules</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Slides</th>
                                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th className="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="bg-white divide-y divide-gray-200">
                                {lessons.map((lesson) => (
                                    <tr key={lesson.id} className="hover:bg-gray-50 transition">
                                        <td className="px-6 py-4">
                                            <div className="text-sm font-medium text-gray-900">{lesson.title}</div>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex items-center gap-2 text-sm text-gray-500">
                                                <Building2 className="h-4 w-4" />
                                                {orgNameById.get(lesson.organization_id) || '—'}
                                            </div>
                                        </td>
                                        <td className="px-6 py-4 text-sm text-gray-900">{lesson.modules_count || 0}</td>
                                        <td className="px-6 py-4 text-sm text-gray-900">{lesson.slides_count || 0}</td>
                                        <td className="px-6 py-4">
                                            <span className={`inline-flex px-2.5 py-0.5 rounded-full text-xs font-medium ${
                                                lesson.status === 'published' || lesson.status === 'live'
                                                    ? 'bg-green-100 text-green-800' 
                                                    : 'bg-gray-100 text-gray-800'
                                            }`}>
                                                {lesson.status}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex items-center justify-end gap-2">
                                                <button className="text-blue-600 hover:text-blue-900">
                                                    <Eye className="h-4 w-4" />
                                                </button>
                                                <button className="text-green-600 hover:text-green-900">
                                                    <Edit className="h-4 w-4" />
                                                </button>
                                                <button className="text-red-600 hover:text-red-900">
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
