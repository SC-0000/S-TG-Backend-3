import React from 'react';
import { 
  ClockIcon, 
  MapPinIcon, 
  VideoCameraIcon,
  UserIcon,
  CalendarDaysIcon 
} from '@heroicons/react/24/outline';
import { CheckCircleIcon as CheckCircleSolid } from '@heroicons/react/24/solid';
import AvailabilityBadge, { EnrollmentProgress } from './AvailabilityBadge';
import { formatTime, formatDate, calculateDuration, getAvailabilityStatus } from '../utils/timeSlotHelpers';

/**
 * SessionCard Component
 * Enhanced card for displaying lesson/session details with availability
 */
export default function SessionCard({ 
  item, 
  type = 'lesson',
  isSelected = false,
  onToggle,
  isDisabled = false,
  showEnrollmentProgress = true,
  className = '' 
}) {
  const isLesson = type === 'lesson';
  const status = getAvailabilityStatus(item);
  const isFull = status.status === 'full';
  
  const handleClick = () => {
    if (!isDisabled && !isFull) {
      onToggle?.();
    }
  };
  
  return (
    <div 
      onClick={handleClick}
      className={`
        relative rounded-xl border-2 transition-all duration-200
        ${isSelected 
          ? 'border-blue-500 bg-blue-50 shadow-md ring-2 ring-blue-500/20' 
          : isDisabled || isFull
            ? 'border-gray-200 bg-gray-50 opacity-60 cursor-not-allowed'
            : 'border-gray-200 bg-white hover:border-blue-300 hover:shadow-sm cursor-pointer'
        }
        ${className}
      `}
    >
      {/* Selection indicator */}
      <div className={`
        absolute top-3 right-3 w-6 h-6 rounded-full flex items-center justify-center
        transition-all duration-200
        ${isSelected 
          ? 'bg-blue-600 text-white' 
          : 'border-2 border-gray-300'
        }
      `}>
        {isSelected && <CheckCircleSolid className="w-6 h-6" />}
      </div>
      
      <div className="p-4">
        {/* Title and availability */}
        <div className="flex items-start justify-between pr-8">
          <h5 className="font-semibold text-gray-900 line-clamp-2">
            {item.title}
          </h5>
        </div>
        
        {/* Availability badge */}
        <div className="mt-2">
          <AvailabilityBadge item={item} size="md" />
        </div>
        
        {/* Description */}
        {item.description && (
          <p className="mt-2 text-sm text-gray-600 line-clamp-2">
            {item.description}
          </p>
        )}
        
        {/* Metadata */}
        <div className="mt-3 space-y-2">
          {/* Date and Time */}
          {isLesson && item.start_time && (
            <div className="flex items-center text-sm text-gray-600">
              <CalendarDaysIcon className="w-4 h-4 mr-2 text-gray-400" />
              <span className="font-medium">{formatDate(item.start_time)}</span>
              <span className="mx-2 text-gray-300">•</span>
              <ClockIcon className="w-4 h-4 mr-1 text-gray-400" />
              <span>{formatTime(item.start_time)}</span>
              {item.end_time && (
                <span className="text-gray-400 ml-1">
                  - {formatTime(item.end_time)}
                </span>
              )}
            </div>
          )}
          
          {/* Duration */}
          {isLesson && item.start_time && item.end_time && (
            <div className="flex items-center text-sm text-gray-500">
              <ClockIcon className="w-4 h-4 mr-2 text-gray-400" />
              <span>{calculateDuration(item.start_time, item.end_time)}</span>
            </div>
          )}
          
          {/* Location/Mode */}
          {isLesson && item.lesson_mode && (
            <div className="flex items-center text-sm text-gray-600">
              {item.lesson_mode === 'online' ? (
                <>
                  <VideoCameraIcon className="w-4 h-4 mr-2 text-blue-500" />
                  <span className="text-blue-600 font-medium">Online Session</span>
                </>
              ) : (
                <>
                  <MapPinIcon className="w-4 h-4 mr-2 text-green-500" />
                  <span className="text-green-600 font-medium">In-Person</span>
                  {item.address && (
                    <span className="text-gray-500 ml-1 truncate">
                      - {item.address}
                    </span>
                  )}
                </>
              )}
            </div>
          )}
          
          {/* Instructor */}
          {item.instructor_name && (
            <div className="flex items-center text-sm text-gray-600">
              <UserIcon className="w-4 h-4 mr-2 text-gray-400" />
              <span>{item.instructor_name}</span>
            </div>
          )}
          
          {/* Assessment deadline */}
          {!isLesson && item.deadline && (
            <div className="flex items-center text-sm text-gray-600">
              <ClockIcon className="w-4 h-4 mr-2 text-gray-400" />
              <span>Due: {new Date(item.deadline).toLocaleDateString()}</span>
            </div>
          )}
        </div>
        
        {/* Enrollment progress bar */}
        {showEnrollmentProgress && item.enrollment_limit && (
          <div className="mt-3 pt-3 border-t border-gray-100">
            <EnrollmentProgress 
              current={item.current_enrollments || 0}
              max={item.enrollment_limit}
              showLabel={true}
            />
          </div>
        )}
        
        {/* Categories/Tags */}
        {item.categories && item.categories.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-1">
            {item.categories.map(cat => (
              <span 
                key={cat}
                className="px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded-full"
              >
                {cat}
              </span>
            ))}
          </div>
        )}
      </div>
      
      {/* Full overlay */}
      {isFull && (
        <div className="absolute inset-0 bg-gray-100/50 rounded-xl flex items-center justify-center">
          <span className="px-3 py-1 bg-red-100 text-red-700 text-sm font-medium rounded-full">
            Session Full
          </span>
        </div>
      )}
    </div>
  );
}

/**
 * CompactSessionCard Component
 * Smaller card variant for list views
 */
export function CompactSessionCard({ 
  item, 
  type = 'lesson',
  isSelected = false,
  onToggle,
  isDisabled = false,
  className = '' 
}) {
  const isLesson = type === 'lesson';
  const status = getAvailabilityStatus(item);
  const isFull = status.status === 'full';
  
  return (
    <div 
      onClick={() => !isDisabled && !isFull && onToggle?.()}
      className={`
        flex items-center p-3 rounded-lg border transition-all
        ${isSelected 
          ? 'border-blue-500 bg-blue-50' 
          : isDisabled || isFull
            ? 'border-gray-200 bg-gray-50 opacity-60 cursor-not-allowed'
            : 'border-gray-200 bg-white hover:border-blue-300 cursor-pointer'
        }
        ${className}
      `}
    >
      {/* Checkbox */}
      <div className={`
        flex-shrink-0 w-5 h-5 rounded-full mr-3 flex items-center justify-center
        ${isSelected ? 'bg-blue-600 text-white' : 'border-2 border-gray-300'}
      `}>
        {isSelected && <CheckCircleSolid className="w-5 h-5" />}
      </div>
      
      {/* Content */}
      <div className="flex-1 min-w-0">
        <div className="flex items-center justify-between">
          <h6 className="font-medium text-gray-900 truncate">{item.title}</h6>
          <AvailabilityBadge item={item} size="sm" className="ml-2 flex-shrink-0" />
        </div>
        
        <div className="flex items-center mt-1 text-xs text-gray-500 space-x-3">
          {isLesson && item.start_time && (
            <span>{formatDate(item.start_time)} at {formatTime(item.start_time)}</span>
          )}
          {item.lesson_mode && (
            <span className={item.lesson_mode === 'online' ? 'text-blue-600' : 'text-green-600'}>
              {item.lesson_mode === 'online' ? '📹 Online' : '📍 In-person'}
            </span>
          )}
        </div>
      </div>
    </div>
  );
}
