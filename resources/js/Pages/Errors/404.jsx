import { Head, Link } from '@inertiajs/react';
import { HomeIcon, ArrowLeftIcon, MagnifyingGlassIcon } from '@heroicons/react/24/outline';

export default function Error404({ status, message }) {
    return (
        <>
            <Head title="404 - Page Not Found" />
            
            <div className="min-h-screen bg-gradient-to-br from-primary-50 via-white to-accent-50 flex items-center justify-center px-4">
                <div className="max-w-2xl w-full text-center">
                    {/* Status Code */}
                    <div className="mb-8">
                        <span className="text-[120px] font-bold text-primary-600 leading-none font-poppins">
                            404
                        </span>
                    </div>

                    {/* Content */}
                    <div className="space-y-6">
                        <h1 className="text-4xl font-bold text-gray-900 font-poppins">
                            Page Not Found
                        </h1>
                        <p className="text-lg text-gray-600 font-nunito max-w-md mx-auto">
                            {message || "The page you're looking for doesn't exist or has been moved."}
                        </p>

                        {/* Action Buttons */}
                        <div className="flex flex-col sm:flex-row gap-4 justify-center pt-4">
                            <Link
                                href="/"
                                className="inline-flex items-center justify-center px-6 py-3 bg-accent hover:bg-accent-600 text-white font-semibold rounded-lg transition-colors duration-200 shadow-md font-poppins"
                            >
                                <HomeIcon className="w-5 h-5 mr-2" />
                                Go Home
                            </Link>
                            
                            <button
                                onClick={() => window.history.back()}
                                className="inline-flex items-center justify-center px-6 py-3 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg transition-colors duration-200 border border-gray-300 shadow-sm font-poppins"
                            >
                                <ArrowLeftIcon className="w-5 h-5 mr-2" />
                                Go Back
                            </button>
                        </div>

                        {/* Quick Links */}
                        <div className="mt-12 pt-8 border-t border-gray-200">
                            <p className="text-sm text-gray-500 mb-4 font-nunito">
                                Looking for something?
                            </p>
                            <div className="flex gap-4 justify-center flex-wrap text-sm">
                                <Link href="/courses" className="text-accent hover:text-accent-600 font-medium transition-colors">
                                    Browse Courses
                                </Link>
                                <span className="text-gray-300">•</span>
                                <Link href="/portal" className="text-accent hover:text-accent-600 font-medium transition-colors">
                                    My Portal
                                </Link>
                                <span className="text-gray-300">•</span>
                                <Link href="/contact" className="text-accent hover:text-accent-600 font-medium transition-colors">
                                    Contact Support
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
