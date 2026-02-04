import React, { useEffect } from 'react';
import { HomeIcon, ArrowPathIcon, ClockIcon } from '@heroicons/react/24/outline';

export default function Error419({ status, message }) {
    const handleRefresh = () => {
        window.location.reload();
    };

    useEffect(() => {
        document.title = '419 - Page Expired';
    }, []);

    return (
        <>
            <div className="min-h-screen bg-gradient-to-br from-orange-50 via-white to-primary-50 flex items-center justify-center px-4">
                <div className="max-w-2xl w-full text-center">
                    {/* Clock Icon */}
                    <div className="mb-8 flex justify-center">
                        <div className="w-24 h-24 bg-orange-100 rounded-full flex items-center justify-center">
                            <ClockIcon className="w-12 h-12 text-orange-600" />
                        </div>
                    </div>

                    {/* Content */}
                    <div className="space-y-6">
                        <div>
                            <span className="text-sm font-semibold text-orange-600 uppercase tracking-wide font-poppins">
                                419 Page Expired
                            </span>
                            <h1 className="text-4xl font-bold text-gray-900 mt-2 font-poppins">
                                Session Expired
                            </h1>
                        </div>
                        <p className="text-lg text-gray-600 font-nunito max-w-md mx-auto">
                            {message || "Your session has expired. Please refresh the page and try again."}
                        </p>

                        {/* Action Buttons */}
                        <div className="flex flex-col sm:flex-row gap-4 justify-center pt-4">
                            <button
                                onClick={handleRefresh}
                                className="inline-flex items-center justify-center px-6 py-3 bg-accent hover:bg-accent-600 text-white font-semibold rounded-lg transition-colors duration-200 shadow-md font-poppins"
                            >
                                <ArrowPathIcon className="w-5 h-5 mr-2" />
                                Refresh Page
                            </button>
                            
                            <a
                                href="/"
                                className="inline-flex items-center justify-center px-6 py-3 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg transition-colors duration-200 border border-gray-300 shadow-sm font-poppins"
                            >
                                <HomeIcon className="w-5 h-5 mr-2" />
                                Go Home
                            </a>
                        </div>

                        {/* Info Section */}
                        <div className="mt-12 pt-8 border-t border-gray-200">
                            <p className="text-sm text-gray-600 font-nunito">
                                This usually happens when you've been inactive for too long. Simply refresh the page to continue.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
