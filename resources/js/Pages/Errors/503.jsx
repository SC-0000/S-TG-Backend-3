import { Head, Link } from '@inertiajs/react';
import { HomeIcon, WrenchScrewdriverIcon } from '@heroicons/react/24/outline';

export default function Error503({ status, message }) {
    return (
        <>
            <Head title="503 - Service Unavailable" />
            
            <div className="min-h-screen bg-gradient-to-br from-primary-50 via-white to-accent-50 flex items-center justify-center px-4">
                <div className="max-w-2xl w-full text-center">
                    {/* Maintenance Icon */}
                    <div className="mb-8 flex justify-center">
                        <div className="w-24 h-24 bg-primary-100 rounded-full flex items-center justify-center">
                            <WrenchScrewdriverIcon className="w-12 h-12 text-primary-600" />
                        </div>
                    </div>

                    {/* Content */}
                    <div className="space-y-6">
                        <div>
                            <span className="text-sm font-semibold text-primary-600 uppercase tracking-wide font-poppins">
                                503 Service Unavailable
                            </span>
                            <h1 className="text-4xl font-bold text-gray-900 mt-2 font-poppins">
                                Under Maintenance
                            </h1>
                        </div>
                        <p className="text-lg text-gray-600 font-nunito max-w-md mx-auto">
                            {message || "We're currently performing scheduled maintenance. We'll be back online shortly."}
                        </p>

                        {/* Action Buttons */}
                        <div className="flex flex-col sm:flex-row gap-4 justify-center pt-4">
                            <button
                                onClick={() => window.location.reload()}
                                className="inline-flex items-center justify-center px-6 py-3 bg-accent hover:bg-accent-600 text-white font-semibold rounded-lg transition-colors duration-200 shadow-md font-poppins"
                            >
                                Check Again
                            </button>
                            
                            <Link
                                href="/"
                                className="inline-flex items-center justify-center px-6 py-3 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg transition-colors duration-200 border border-gray-300 shadow-sm font-poppins"
                            >
                                <HomeIcon className="w-5 h-5 mr-2" />
                                Go Home
                            </Link>
                        </div>

                        {/* Status Updates */}
                        <div className="mt-12 pt-8 border-t border-gray-200">
                            <p className="text-sm text-gray-600 font-nunito">
                                For status updates, please check our{' '}
                                <Link href="/contact" className="text-accent hover:text-accent-600 font-medium transition-colors">
                                    contact page
                                </Link>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
