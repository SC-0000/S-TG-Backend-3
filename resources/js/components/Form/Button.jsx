import React from 'react';
import LoadingSpinner from '../LoadingSpinner';

export default function Button({ 
  loading = false,
  disabled = false,
  children, 
  className = '',
  variant = 'primary',
  size = 'md',
  type = 'button',
  ...props 
}) {
  const variants = {
    primary: 'bg-blue-500 hover:bg-blue-600 text-white border-blue-500',
    secondary: 'bg-gray-500 hover:bg-gray-600 text-white border-gray-500',
    success: 'bg-green-500 hover:bg-green-600 text-white border-green-500',
    danger: 'bg-red-500 hover:bg-red-600 text-white border-red-500',
    outline: 'bg-transparent hover:bg-gray-100 text-gray-700 border-gray-300',
  };

  const sizes = {
    sm: 'px-3 py-1.5 text-sm',
    md: 'px-4 py-2 text-base',
    lg: 'px-6 py-3 text-lg',
  };

  const isDisabled = loading || disabled;

  return (
    <button
      type={type}
      className={`
        inline-flex items-center justify-center gap-2
        font-semibold rounded-lg border
        transition-all duration-200
        focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500
        disabled:opacity-50 disabled:cursor-not-allowed
        ${variants[variant]}
        ${sizes[size]}
        ${!isDisabled && 'hover:scale-105 active:scale-95'}
        ${className}
      `}
      disabled={isDisabled}
      {...props}
    >
      {loading && <LoadingSpinner size="sm" color="white" />}
      {children}
    </button>
  );
}