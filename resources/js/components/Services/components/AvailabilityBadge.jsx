import React from 'react';
import { getAvailabilityStatus } from '../utils/timeSlotHelpers';

/**
 * AvailabilityBadge Component
 * Shows color-coded availability status
 */
export default function AvailabilityBadge({ 
  item, 
  showProgress = false,
  size = 'md',
  className = '' 
}) {
  const status = getAvailabilityStatus(item);
  
  const sizeClasses = {
    sm: 'px-1.5 py-0.5 text-[10px]',
    md: 'px-2 py-1 text-xs',
    lg: 'px-3 py-1.5 text-sm'
  };
  
  return (
    <div className={`inline-flex items-center ${className}`}>
      <span className={`
        inline-flex items-center font-medium rounded-full border
        ${status.bgColor} ${status.textColor} ${status.borderColor}
        ${sizeClasses[size]}
      `}>
        {/* Status dot */}
        <span className={`
          w-1.5 h-1.5 rounded-full mr-1.5
          ${status.color === 'green' ? 'bg-green-500' : ''}
          ${status.color === 'yellow' ? 'bg-yellow-500' : ''}
          ${status.color === 'orange' ? 'bg-orange-500' : ''}
          ${status.color === 'red' ? 'bg-red-500' : ''}
          ${status.color === 'blue' ? 'bg-blue-500' : ''}
        `} />
        {status.text}
      </span>
      
      {/* Progress bar */}
      {showProgress && item.enrollment_limit && (
        <EnrollmentProgress 
          current={item.current_enrollments || 0}
          max={item.enrollment_limit}
          className="ml-2"
        />
      )}
    </div>
  );
}

/**
 * EnrollmentProgress Component
 * Visual progress bar showing enrollment status
 */
export function EnrollmentProgress({ 
  current, 
  max, 
  showLabel = true,
  className = '' 
}) {
  if (!max) return null;
  
  const percentage = Math.min((current / max) * 100, 100);
  
  let barColor = 'bg-green-500';
  if (percentage >= 90) barColor = 'bg-red-500';
  else if (percentage >= 75) barColor = 'bg-orange-500';
  else if (percentage >= 50) barColor = 'bg-yellow-500';
  
  return (
    <div className={`flex items-center ${className}`}>
      <div className="w-20 h-2 bg-gray-200 rounded-full overflow-hidden">
        <div 
          className={`h-full ${barColor} transition-all duration-300`}
          style={{ width: `${percentage}%` }}
        />
      </div>
      {showLabel && (
        <span className="ml-2 text-xs text-gray-500">
          {current}/{max}
        </span>
      )}
    </div>
  );
}

/**
 * AvailabilityIndicator Component
 * Simple dot indicator for compact views
 */
export function AvailabilityIndicator({ 
  item, 
  size = 'md',
  className = '' 
}) {
  const status = getAvailabilityStatus(item);
  
  const sizeClasses = {
    sm: 'w-2 h-2',
    md: 'w-2.5 h-2.5',
    lg: 'w-3 h-3'
  };
  
  return (
    <span 
      className={`
        inline-block rounded-full
        ${sizeClasses[size]}
        ${status.color === 'green' ? 'bg-green-500' : ''}
        ${status.color === 'yellow' ? 'bg-yellow-500' : ''}
        ${status.color === 'orange' ? 'bg-orange-500' : ''}
        ${status.color === 'red' ? 'bg-red-500' : ''}
        ${status.color === 'blue' ? 'bg-blue-500' : ''}
        ${className}
      `}
      title={status.text}
    />
  );
}

/**
 * StatusText Component
 * Text-only availability display
 */
export function StatusText({ 
  item, 
  className = '' 
}) {
  const status = getAvailabilityStatus(item);
  
  return (
    <span className={`${status.textColor} ${className}`}>
      {status.text}
    </span>
  );
}
