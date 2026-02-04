import React, { useEffect } from 'react';
import { HomeIcon, ArrowPathIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';

export default function Error500({ status, message, debug }) {
    useEffect(() => {
        document.title = '500 - Server Error';
    }, []);

    return (
        <>
            <div className="min-h-screen bg-gradient-to-br from-red-50 via-white to-primary-50 flex items-center justify-center px-4">
                <div className="max-w-2xl w-full text-center">
                    {/* Error Icon */}
                    <div className="mb-8 flex justify-center">
                        <div className="w-24 h-24 bg-red-100 rounded-full flex items-center justify-center">
                            <ExclamationTriangleIcon className="w-12 h-12 text-red-600" />
                        </div>
                    </div>

                    {/* Content */}
                    <div className="space-y-6">
                        <div>
                            <span className="text-sm font-semibold text-red-600 uppercase tracking-wide font-poppins">
                                500 Error
                            </span>
                            <h1 className="text-4xl font-bold text-gray-900 mt-2 font-poppins">
                                Something Went Wrong
                            </h1>
                        </div>
                        <p className="text-lg text-gray-600 font-nunito max-w-md mx-auto">
                            {message || "We're experiencing technical difficulties. Our team has been notified and is working on a fix."}
                        </p>

                        {/* Debug Info (development only) */}
                        {debug && (
                            <div className="mt-6 p-4 bg-red-50 border border-red-200 rounded-lg text-left max-w-2xl mx-auto">
                                <p className="text-xs text-red-800 font-mono break-all">{debug}</p>
                            </div>
                        )}

                        {/* Action Buttons */}
                        <div className="flex flex-col sm:flex-row gap-4 justify-center pt-4">
                            <button
                                onClick={() => window.location.reload()}
                                className="inline-flex items-center justify-center px-6 py-3 bg-accent hover:bg-accent-600 text-white font-semibold rounded-lg transition-colors duration-200 shadow-md font-poppins"
                            >
                                <ArrowPathIcon className="w-5 h-5 mr-2" />
                                Try Again
                            </button>
                            
                            <a
                                href="/"
                                className="inline-flex items-center justify-center px-6 py-3 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg transition-colors duration-200 border border-gray-300 shadow-sm font-poppins"
                            >
                                <HomeIcon className="w-5 h-5 mr-2" />
                                Go Home
                            </a>
                        </div>

                        {/* Support Contact */}
                        <div className="mt-12 pt-8 border-t border-gray-200">
                            <p className="text-sm text-gray-600 font-nunito">
                                Need immediate assistance?{' '}
                                <a href="/contact" className="text-accent hover:text-accent-600 font-medium transition-colors">
                                    Contact our support team
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
