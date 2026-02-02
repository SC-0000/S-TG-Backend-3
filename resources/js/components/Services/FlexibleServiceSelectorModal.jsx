import React from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { XMarkIcon } from '@heroicons/react/24/outline';
import FlexibleServiceSelector from './FlexibleServiceSelector';

export default function FlexibleServiceSelectorModal({
  isOpen,
  onClose,
  service,
  availableContent,
  onConfirm,
  isLoading = false
}) {
  const [selections, setSelections] = React.useState({
    lessons: [],
    assessments: [],
    isValid: false
  });

  const handleSelectionChange = (newSelections) => {
    setSelections(newSelections);
  };

  const handleConfirm = () => {
    if (selections.isValid) {
      onConfirm(selections);
    }
  };

  if (!isOpen) return null;

  return (
    <AnimatePresence>
      {isOpen && (
        <>
          {/* Backdrop */}
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            exit={{ opacity: 0 }}
            onClick={onClose}
            className="fixed inset-0 bg-black/50 backdrop-blur-sm z-50"
          />

          {/* Modal */}
          <div className="fixed inset-0 z-50 overflow-y-auto">
            <div className="flex min-h-full items-center justify-center p-4">
              <motion.div
                initial={{ opacity: 0, scale: 0.95, y: 20 }}
                animate={{ opacity: 1, scale: 1, y: 0 }}
                exit={{ opacity: 0, scale: 0.95, y: 20 }}
                className="relative w-full max-w-6xl bg-white rounded-2xl shadow-2xl"
                onClick={(e) => e.stopPropagation()}
              >
                {/* Header */}
                <div className="sticky top-0 z-10 bg-gradient-to-r from-blue-600 to-indigo-600 text-white px-6 py-4 rounded-t-2xl">
                  <div className="flex items-center justify-between">
                    <div>
                      <h2 className="text-2xl font-bold">Customize Your Service Package</h2>
                      <p className="text-blue-100 mt-1">
                        Select your preferred lessons and assessments
                      </p>
                    </div>
                    <button
                      onClick={onClose}
                      className="p-2 hover:bg-white/20 rounded-lg transition-colors"
                    >
                      <XMarkIcon className="w-6 h-6" />
                    </button>
                  </div>
                </div>

                {/* Content */}
                <div className="p-6 max-h-[70vh] overflow-y-auto">
                  <FlexibleServiceSelector
                    service={service}
                    availableContent={availableContent}
                    onSelectionChange={handleSelectionChange}
                  />
                </div>

                {/* Footer */}
                <div className="sticky bottom-0 bg-gray-50 border-t border-gray-200 px-6 py-4 rounded-b-2xl">
                  <div className="flex flex-col sm:flex-row items-center justify-between gap-4">
                    {/* Selection Summary */}
                    <div className="text-sm text-gray-600">
                      {selections.isValid ? (
                        <span className="flex items-center text-green-600 font-medium">
                          <svg className="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                          </svg>
                          Selection complete!
                        </span>
                      ) : (
                        <span className="flex items-center text-amber-600 font-medium">
                          <svg className="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                          </svg>
                          Please complete your selection
                        </span>
                      )}
                    </div>

                    {/* Action Buttons */}
                    <div className="flex gap-3">
                      <button
                        onClick={onClose}
                        disabled={isLoading}
                        className="px-6 py-3 rounded-lg font-semibold text-gray-700 bg-white border-2 border-gray-300 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-all"
                      >
                        Cancel
                      </button>
                      <button
                        onClick={handleConfirm}
                        disabled={!selections.isValid || isLoading}
                        className={`
                          px-8 py-3 rounded-lg font-semibold text-white transition-all shadow-lg
                          ${selections.isValid && !isLoading
                            ? 'bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 hover:shadow-xl transform hover:-translate-y-0.5'
                            : 'bg-gray-400 cursor-not-allowed'
                          }
                        `}
                      >
                        {isLoading ? (
                          <span className="flex items-center gap-2">
                            <motion.div
                              className="w-5 h-5 border-2 border-white border-t-transparent rounded-full"
                              animate={{ rotate: 360 }}
                              transition={{ duration: 1, repeat: Infinity, ease: "linear" }}
                            />
                            Adding to Cart...
                          </span>
                        ) : (
                          'Confirm & Add to Cart'
                        )}
                      </button>
                    </div>
                  </div>

                  {/* Validation Message */}
                  {!selections.isValid && selections.lessons.length + selections.assessments.length > 0 && (
                    <div className="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-800">
                      Please complete your required selections to continue
                    </div>
                  )}
                </div>
              </motion.div>
            </div>
          </div>
        </>
      )}
    </AnimatePresence>
  );
}
