import React from 'react';
import { motion } from 'framer-motion';
import { XMarkIcon } from '@heroicons/react/24/outline';
import { FaCheckCircle, FaExclamationCircle } from 'react-icons/fa';

const Toast = ({ message, type = 'success', onClose }) => (
  <motion.div
    initial={{ opacity: 0, y: -50, x: 300 }}
    animate={{ opacity: 1, y: 0, x: 0 }}
    exit={{ opacity: 0, y: -50, x: 300 }}
    className={`fixed top-20 right-4 z-50 flex items-center gap-3 px-6 py-4 rounded-lg shadow-lg min-w-80 ${
      type === 'success' 
        ? 'bg-green-500 text-white' 
        : type === 'error' 
        ? 'bg-red-500 text-white'
        : 'bg-blue-500 text-white'
    }`}
  >
    <div className="flex items-center gap-3 flex-1">
      {type === 'success' && (
        <FaCheckCircle className="w-5 h-5" />
      )}
      {type === 'error' && (
        <FaExclamationCircle className="w-5 h-5" />
      )}
      <span className="font-medium">{message}</span>
    </div>
    <button
      onClick={onClose}
      className="text-white/80 hover:text-white transition-colors"
    >
      <XMarkIcon className="w-5 h-5" />
    </button>
  </motion.div>
);

export default Toast;
