import React, { useEffect, useMemo, useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    AlertCircle, CheckCircle, XCircle, Info, AlertTriangle,
    Search, Filter, Download, RefreshCw, Calendar, Clock
} from 'lucide-react';
import { apiClient } from '@/api';
import { useToast } from '@/contexts/ToastContext';

export default function SystemLogs() {
    const { showError } = useToast();
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedLevel, setSelectedLevel] = useState('all');
    const [selectedDate, setSelectedDate] = useState('today');
    const [logs, setLogs] = useState([]);
    const [loading, setLoading] = useState(true);

    const fetchLogs = async () => {
        try {
            setLoading(true);
            const response = await apiClient.get('/superadmin/logs/system', { useToken: true });
            setLogs(Array.isArray(response?.data) ? response.data : []);
        } catch (error) {
            showError(error.message || 'Unable to load system logs.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchLogs();
    }, []);

    const getLevelColor = (level) => {
        const colors = {
            error: 'bg-red-100 text-red-800 border-red-200',
            warning: 'bg-yellow-100 text-yellow-800 border-yellow-200',
            info: 'bg-blue-100 text-blue-800 border-blue-200',
            success: 'bg-green-100 text-green-800 border-green-200',
        };
        return colors[level] || 'bg-gray-100 text-gray-800 border-gray-200';
    };

    const getLevelIcon = (level) => {
        switch (level) {
            case 'error':
                return <XCircle className="h-5 w-5 text-red-600" />;
            case 'warning':
                return <AlertTriangle className="h-5 w-5 text-yellow-600" />;
            case 'success':
                return <CheckCircle className="h-5 w-5 text-green-600" />;
            case 'info':
            default:
                return <Info className="h-5 w-5 text-blue-600" />;
        }
    };

    const filteredLogs = useMemo(() => {
        return logs.filter(log => {
            const message = (log.message || '').toLowerCase();
            const matchesSearch = message.includes(searchTerm.toLowerCase());
            const matchesLevel = selectedLevel === 'all' || log.level === selectedLevel;
            return matchesSearch && matchesLevel;
        });
    }, [logs, searchTerm, selectedLevel]);

    const stats = {
        total: logs.length,
        errors: logs.filter(l => l.level === 'error').length,
        warnings: logs.filter(l => l.level === 'warning').length,
        info: logs.filter(l => l.level === 'info').length,
    };

    return (
        <SuperAdminLayout>
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">System Logs</h1>
                        <p className="text-gray-500 mt-1">Monitor system events and errors</p>
                    </div>
                    <div className="flex gap-3">
                        <button
                            type="button"
                            onClick={fetchLogs}
                            className="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50 transition"
                        >
                            <RefreshCw className="h-4 w-4" />
                            Refresh
                        </button>
                        <button className="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
                            <Download className="h-4 w-4" />
                            Export Logs
                        </button>
                    </div>
                </div>

                {/* Statistics Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-gray-500">Total Logs</p>
                                <p className="text-2xl font-bold text-gray-900 mt-1">{stats.total}</p>
                            </div>
                            <div className="p-3 bg-gray-100 rounded-lg">
                                <AlertCircle className="h-6 w-6 text-gray-600" />
                            </div>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-gray-500">Errors</p>
                                <p className="text-2xl font-bold text-red-600 mt-1">{stats.errors}</p>
                            </div>
                            <div className="p-3 bg-red-100 rounded-lg">
                                <XCircle className="h-6 w-6 text-red-600" />
                            </div>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-gray-500">Warnings</p>
                                <p className="text-2xl font-bold text-yellow-600 mt-1">{stats.warnings}</p>
                            </div>
                            <div className="p-3 bg-yellow-100 rounded-lg">
                                <AlertTriangle className="h-6 w-6 text-yellow-600" />
                            </div>
                        </div>
                    </div>

                    <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                        <div className="flex items-center justify-between">
                            <div>
                                <p className="text-sm text-gray-500">Info</p>
                                <p className="text-2xl font-bold text-blue-600 mt-1">{stats.info}</p>
                            </div>
                            <div className="p-3 bg-blue-100 rounded-lg">
                                <Info className="h-6 w-6 text-blue-600" />
                            </div>
                        </div>
                    </div>
                </div>

                {/* Filters */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div className="flex flex-col md:flex-row gap-4">
                        {/* Search */}
                        <div className="flex-1">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-5 w-5 text-gray-400" />
                                <input
                                    type="text"
                                    placeholder="Search logs..."
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                />
                            </div>
                        </div>

                        {/* Level Filter */}
                        <div className="w-full md:w-48">
                            <select
                                value={selectedLevel}
                                onChange={(e) => setSelectedLevel(e.target.value)}
                                className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            >
                                <option value="all">All Levels</option>
                                <option value="error">Error</option>
                                <option value="warning">Warning</option>
                                <option value="info">Info</option>
                                <option value="success">Success</option>
                            </select>
                        </div>
                    </div>
                </div>

                {/* Logs List */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    {loading ? (
                        <div className="p-6 text-center text-gray-600">Loading logs...</div>
                    ) : (
                        <div className="divide-y divide-gray-200">
                            {filteredLogs.map((log) => (
                                <div key={log.id || `${log.timestamp}-${log.message}`} className="p-6 hover:bg-gray-50 transition">
                                    <div className="flex items-start gap-4">
                                        <div className="mt-1">
                                            {getLevelIcon(log.level)}
                                        </div>
                                        <div className="flex-1">
                                            <div className="flex items-center gap-3 mb-2">
                                                <span className={`px-2 py-1 text-xs font-medium rounded-full ${getLevelColor(log.level)}`}>
                                                    {log.level}
                                                </span>
                                                <span className="text-sm text-gray-500 flex items-center gap-1">
                                                    <Clock className="h-4 w-4" />
                                                    {log.timestamp}
                                                </span>
                                            </div>
                                            <p className="text-gray-900 font-medium">{log.message}</p>
                                            {log.context && (
                                                <pre className="mt-3 p-3 bg-gray-100 rounded-lg text-xs text-gray-700 overflow-auto">
                                                    {JSON.stringify(log.context, null, 2)}
                                                </pre>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </SuperAdminLayout>
    );
}
