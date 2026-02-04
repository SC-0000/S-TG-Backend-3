import React, { useEffect } from 'react';
import { HomeIcon, LockClosedIcon } from '@heroicons/react/24/outline';

export default function Error403({ status, message }) {
    useEffect(() => {
        document.title = '403 - Forbidden';
    }, []);

    return (
        <>
            <div className="min-h-screen bg-gradient-to-br from-yellow-50 via-white to-primary-50 flex items-center justify-center px-4">
                <div className="max-w-2xl w-full text-center">
                    {/* Lock Icon */}
                    <div className="mb-8 flex justify-center">
                        <div className="w-24 h-24 bg-yellow-100 rounded-full flex items-center justify-center">
                            <LockClosedIcon className="w-12 h-12 text-yellow-600" />
                        </div>
                    </div>

                    {/* Content */}
                    <div className="space-y-6">
                        <div>
                            <span className="text-sm font-semibold text-yellow-600 uppercase tracking-wide font-poppins">
                                403 Forbidden
                            </span>
                            <h1 className="text-4xl font-bold text-gray-900 mt-2 font-poppins">
                                Access Denied
                            </h1>
                        </div>
                        <p className="text-lg text-gray-600 font-nunito max-w-md mx-auto">
                            {message || "You don't have permission to access this resource."}
                        </p>

                        {/* Action Buttons */}
                        <div className="flex flex-col sm:flex-row gap-4 justify-center pt-4">
                            <a
                                href="/"
                                className="inline-flex items-center justify-center px-6 py-3 bg-accent hover:bg-accent-600 text-white font-semibold rounded-lg transition-colors duration-200 shadow-md font-poppins"
                            >
                                <HomeIcon className="w-5 h-5 mr-2" />
                                Go Home
                            </a>
                            
                            <button
                                onClick={() => window.history.back()}
                                className="inline-flex items-center justify-center px-6 py-3 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg transition-colors duration-200 border border-gray-300 shadow-sm font-poppins"
                            >
                                Go Back
                            </button>
                        </div>

                        {/* Help Section */}
                        <div className="mt-12 pt-8 border-t border-gray-200">
                            <p className="text-sm text-gray-600 font-nunito">
                                If you believe this is an error, please{' '}
                                <a href="/contact" className="text-accent hover:text-accent-600 font-medium transition-colors">
                                    contact support
                                </a>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
