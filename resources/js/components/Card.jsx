import React from 'react';

export default function Card({ 
  children, 
  className = '',
  padding = 'default',
  hover = false,
  onClick,
  ...props 
}) {
  const paddingClasses = {
    none: '',
    sm: 'p-3',
    default: 'p-4 sm:p-6',
    lg: 'p-6 sm:p-8',
  };

  return (
    <div
      className={`
        bg-white rounded-lg shadow-sm border border-gray-200
        transition-all duration-200
        ${paddingClasses[padding]}
        ${hover && 'hover:shadow-md hover:border-gray-300 cursor-pointer'}
        ${onClick && 'cursor-pointer'}
        ${className}
      `}
      onClick={onClick}
      {...props}
    >
      {children}
    </div>
  );
}

// Sub-components for structured cards
Card.Header = function CardHeader({ children, className = '' }) {
  return (
    <div className={`border-b border-gray-200 pb-4 mb-4 ${className}`}>
      {children}
    </div>
  );
};

Card.Title = function CardTitle({ children, className = '' }) {
  return (
    <h3 className={`text-lg font-semibold text-gray-900 ${className}`}>
      {children}
    </h3>
  );
};

Card.Body = function CardBody({ children, className = '' }) {
  return (
    <div className={className}>
      {children}
    </div>
  );
};

Card.Footer = function CardFooter({ children, className = '' }) {
  return (
    <div className={`border-t border-gray-200 pt-4 mt-4 ${className}`}>
      {children}
    </div>
  );
};