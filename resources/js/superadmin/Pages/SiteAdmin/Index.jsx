import React, { useState } from 'react';
import SuperAdminLayout from '@/superadmin/Layouts/SuperAdminLayout';
import { 
    BookOpen, Users, GraduationCap, FileText, Calendar, 
    ClipboardCheck, MessageSquare, AlertCircle, Award, 
    Settings, Package, Shield, Video, Search, 
    ChevronRight, Sparkles, FileQuestion, Clock,
    UserCheck, BookMarked, Target, TrendingUp, Map,
    Home, Star
} from 'lucide-react';
import { BanknotesIcon } from '@heroicons/react/24/outline';


const routeMap = {
    'admin.courses.index': '/admin/courses',
    'admin.content-lessons.index': '/admin/content-lessons',
    'lessons.index': '/admin/lessons',
    'assessments.index': '/assessments',
    'admin.questions.index': '/admin/questions',
    'admin.articles.index': '/admin/articles',
    'faqs.index': '/faqs',
    'children.index': '/children',
    'teachers.index': '/teachers',
    'teacher.applications.index': '/applications',
    'admin.teacher-student-assignments.index': '/admin/teacher-student-assignments',
    'subscriptions.index': '/subscriptions',
    'transactions.index': '/transactions',
    'user_subscriptions.index': '/user-subscriptions',
    'admin.access.index': '/access',
    'admin.live-sessions.index': '/admin/live-sessions',
    'homework.index': '/homework',
    'submissions.index': '/submissions',
    'attendance.overview': '/attendance',
    'admin_tasks.index': '/admin-tasks',
    'feedbacks.index': '/feedbacks',
    'portal.feedback.index': '/admin/portal-feedbacks',
    'services.admin.index': '/admin/services',
    'products.index': '/products',
    'journeys.index': '/journeys',
    'journey-categories.index': '/journey-categories',
    'slides.index': '/slides',
    'alerts.index': '/alerts',
    'testimonials.index': '/testimonials',
    'milestones.index': '/milestones',
    'applications.index': '/applications',
};

const route = (name) => {
    if (typeof window !== 'undefined' && typeof window.route === 'function') {
        return window.route(name);
    }
    return routeMap[name] || '#';
};

const adminFeatures = [
    {
        category: 'Content Management',
        icon: BookOpen,
        color: 'purple',
        features: [
            { name: 'Courses', route: route('admin.courses.index'), icon: BookMarked, description: 'Manage course hierarchy' },
            { name: 'Content Lessons', route: route('admin.content-lessons.index'), icon: FileText, description: 'Block-based lessons' },
            { name: 'Legacy Lessons', route: route('lessons.index'), icon: BookOpen, description: 'Traditional lessons' },
            { name: 'Assessments', route: route('assessments.index'), icon: ClipboardCheck, description: 'Tests and quizzes' },
            { name: 'Question Bank', route: route('admin.questions.index'), icon: FileQuestion, description: 'Question library' },
            { name: 'Articles', route: route('admin.articles.index'), icon: FileText, description: 'Educational articles' },
            { name: 'FAQs', route: route('faqs.index'), icon: MessageSquare, description: 'Frequently asked questions' },
        ]
    },
    {
        category: 'User Management',
        icon: Users,
        color: 'blue',
        features: [
            { name: 'Children', route: route('children.index'), icon: Users, description: 'Student accounts' },
            { name: 'Teachers', route: route('teachers.index'), icon: GraduationCap, description: 'Teacher management' },
            { name: 'Teacher Applications', route: route('teacher.applications.index'), icon: UserCheck, description: 'Review applications' },
            { name: 'Teacher-Student Assignments', route: route('admin.teacher-student-assignments.index'), icon: Target, description: 'Link teachers to students' },
            // { name: 'Year Groups', route: route('admin.year-groups.index'), icon: Award, description: 'Year group management' },
        ]
    },
    {
        category: 'Subscriptions & Access',
        icon: Shield,
        color: 'green',
        features: [
            { name: 'Subscriptions', route: route('subscriptions.index'), icon: Package, description: 'Subscription plans' },
            { name: 'Transactions', route: route('transactions.index'), icon: BanknotesIcon, description: 'All payments across orgs' },
            { name: 'User Subscriptions', route: route('user_subscriptions.index'), icon: UserCheck, description: 'Grant/revoke access' },
            { name: 'Access Control', route: route('admin.access.index'), icon: Shield, description: 'Permission management' },
        ]
    },
    {
        category: 'Learning Tools',
        icon: Video,
        color: 'red',
        features: [
            { name: 'Live Sessions', route: route('admin.live-sessions.index'), icon: Video, description: 'WebSocket live teaching' },
            { name: 'Homework', route: route('homework.index'), icon: ClipboardCheck, description: 'Assignments' },
            { name: 'Submissions', route: route('submissions.index'), icon: FileText, description: 'Student submissions' },
            // { name: 'AI Grading Flags', route: route('admin.flags.index'), icon: Sparkles, description: 'Review AI grading' },
            // { name: 'Upload Reviews', route: route('admin.lesson-uploads.pending'), icon: Clock, description: 'Pending uploads' },
        ]
    },
    {
        category: 'Operations & Tasks',
        icon: Settings,
        color: 'orange',
        features: [
            { name: 'Attendance', route: route('attendance.overview'), icon: Calendar, description: 'Track attendance' },
            { name: 'Admin Tasks', route: route('admin_tasks.index'), icon: ClipboardCheck, description: 'Task management' },
            { name: 'Feedbacks', route: route('feedbacks.index'), icon: MessageSquare, description: 'User feedback' },
            { name: 'Portal Feedbacks', route: route('portal.feedback.index'), icon: MessageSquare, description: 'Parent feedback' },
        ]
    },
    {
        category: 'Platform Content',
        icon: Home,
        color: 'indigo',
        features: [
            { name: 'Services', route: route('services.admin.index'), icon: Package, description: 'Platform services' },
            { name: 'Products', route: route('products.index'), icon: Package, description: 'Product management' },
            { name: 'Journeys', route: route('journeys.index'), icon: Map, description: 'Learning journeys' },
            { name: 'Journey Categories', route: route('journey-categories.index'), icon: Target, description: 'Journey organization' },
            { name: 'Slides', route: route('slides.index'), icon: FileText, description: 'Landing page slides' },
            { name: 'Alerts', route: route('alerts.index'), icon: AlertCircle, description: 'System alerts' },
            { name: 'Testimonials', route: route('testimonials.index'), icon: Star, description: 'User testimonials' },
            { name: 'Milestones', route: route('milestones.index'), icon: Award, description: 'Achievement milestones' },
        ]
    },
    {
        category: 'Applications',
        icon: FileText,
        color: 'teal',
        features: [
            { name: 'Teacher Applications', route: route('teacher.applications.index'), icon: UserCheck, description: 'Teacher signup requests' },
            { name: 'General Applications', route: route('applications.index'), icon: FileText, description: 'Other applications' },
        ]
    },
];

const colorClasses = {
    purple: {
        bg: 'bg-purple-100',
        text: 'text-purple-600',
        border: 'border-purple-200',
        hover: 'hover:bg-purple-50'
    },
    blue: {
        bg: 'bg-blue-100',
        text: 'text-blue-600',
        border: 'border-blue-200',
        hover: 'hover:bg-blue-50'
    },
    green: {
        bg: 'bg-green-100',
        text: 'text-green-600',
        border: 'border-green-200',
        hover: 'hover:bg-green-50'
    },
    red: {
        bg: 'bg-red-100',
        text: 'text-red-600',
        border: 'border-red-200',
        hover: 'hover:bg-red-50'
    },
    orange: {
        bg: 'bg-orange-100',
        text: 'text-orange-600',
        border: 'border-orange-200',
        hover: 'hover:bg-orange-50'
    },
    indigo: {
        bg: 'bg-indigo-100',
        text: 'text-indigo-600',
        border: 'border-indigo-200',
        hover: 'hover:bg-indigo-50'
    },
    teal: {
        bg: 'bg-teal-100',
        text: 'text-teal-600',
        border: 'border-teal-200',
        hover: 'hover:bg-teal-50'
    },
};

export default function SiteAdminIndex() {
    const [searchTerm, setSearchTerm] = useState('');

    const filteredFeatures = adminFeatures.map(section => ({
        ...section,
        features: section.features.filter(feature =>
            feature.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
            feature.description.toLowerCase().includes(searchTerm.toLowerCase())
        )
    })).filter(section => section.features.length > 0);

    return (
        <SuperAdminLayout>
            
            <div className="space-y-8">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Site Administration</h1>
                        <p className="text-gray-500 mt-1">Complete access to all platform management tools</p>
                    </div>
                </div>

                {/* Search Bar */}
                <div className="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div className="relative">
                        <Search className="absolute left-4 top-1/2 -translate-y-1/2 h-5 w-5 text-gray-400" />
                        <input
                            type="text"
                            placeholder="Search features..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="w-full pl-12 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                        />
                    </div>
                </div>

                {/* Feature Sections */}
                <div className="space-y-8">
                    {filteredFeatures.map((section) => {
                        const CategoryIcon = section.icon;
                        const colors = colorClasses[section.color];
                        
                        return (
                            <div key={section.category} className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                                {/* Section Header */}
                                <div className={`px-6 py-4 ${colors.bg} border-b ${colors.border}`}>
                                    <div className="flex items-center gap-3">
                                        <div className={`p-2 rounded-lg bg-white`}>
                                            <CategoryIcon className={`h-6 w-6 ${colors.text}`} />
                                        </div>
                                        <div>
                                            <h2 className={`text-xl font-bold ${colors.text}`}>{section.category}</h2>
                                            <p className="text-sm text-gray-600">{section.features.length} features available</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Feature Grid */}
                                <div className="p-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    {section.features.map((feature) => {
                                        const FeatureIcon = feature.icon;
                                        
                                        return (
                                            <a
                                                key={feature.name}
                                                href={feature.route}
                                                className={`group flex items-start gap-4 p-4 rounded-lg border ${colors.border} ${colors.hover} transition-all hover:shadow-md`}
                                            >
                                                <div className={`p-2 rounded-lg ${colors.bg} group-hover:scale-110 transition-transform`}>
                                                    <FeatureIcon className={`h-5 w-5 ${colors.text}`} />
                                                </div>
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <h3 className={`font-semibold ${colors.text} group-hover:underline`}>
                                                            {feature.name}
                                                        </h3>
                                                        <ChevronRight className={`h-4 w-4 ${colors.text} opacity-0 group-hover:opacity-100 transition-opacity`} />
                                                    </div>
                                                    <p className="text-sm text-gray-600 mt-1">{feature.description}</p>
                                                </div>
                                            </a>
                                        );
                                    })}
                                </div>
                            </div>
                        );
                    })}
                </div>

                {/* No Results */}
                {filteredFeatures.length === 0 && (
                    <div className="text-center py-12">
                        <Search className="h-12 w-12 text-gray-400 mx-auto mb-4" />
                        <h3 className="text-lg font-semibold text-gray-900 mb-2">No features found</h3>
                        <p className="text-gray-500">Try searching with different keywords</p>
                    </div>
                )}
            </div>
        </SuperAdminLayout>
    );
}
