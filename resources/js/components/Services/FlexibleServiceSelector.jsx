import React, { useState, useMemo, useEffect } from 'react';
import { 
  AcademicCapIcon,
  DocumentTextIcon,
  MagnifyingGlassIcon,
  CalendarDaysIcon,
  ClockIcon,
  MapPinIcon,
  VideoCameraIcon
} from '@heroicons/react/24/outline';
import { CheckCircleIcon as CheckCircleSolid } from '@heroicons/react/24/solid';

// Import components
import MiniCalendar from './components/MiniCalendar';
import { 
  getAvailabilityStatus,
  formatDate,
  formatTime,
  formatDateTimeRange
} from './utils/timeSlotHelpers';

/**
 * Simplified FlexibleServiceSelector Component
 * Clean, compact yet powerful UI for time slot selection
 */
export default function FlexibleServiceSelector({ 
  service, 
  availableContent,
  onSelectionChange 
}) {
  // Selection state
  const [selectedLessons, setSelectedLessons] = useState([]);
  const [selectedAssessments, setSelectedAssessments] = useState([]);
  
  // Simple filter state
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedDate, setSelectedDate] = useState(null);

  const { lessons = [], assessments = [] } = availableContent || {};
  const { selection_config } = service;
  const requiredLessons = selection_config?.live_sessions || 0;
  const requiredAssessments = selection_config?.assessments || 0;

  // Show search only if there are many items
  const showSearch = lessons.length > 6 || assessments.length > 6;

  // Filter lessons
  const filteredLessons = useMemo(() => {
    return lessons.filter(lesson => {
      const matchesSearch = !searchQuery || 
        lesson.title.toLowerCase().includes(searchQuery.toLowerCase());
      
      const matchesDate = !selectedDate || 
        (lesson.start_time && 
         new Date(lesson.start_time).toDateString() === selectedDate.toDateString());
      
      return matchesSearch && matchesDate;
    });
  }, [lessons, searchQuery, selectedDate]);

  // Filter assessments
  const filteredAssessments = useMemo(() => {
    return assessments.filter(assessment => {
      return !searchQuery || 
        assessment.title.toLowerCase().includes(searchQuery.toLowerCase());
    });
  }, [assessments, searchQuery]);

  // Handle lesson selection
  const toggleLesson = (lessonId) => {
    const lesson = lessons.find(l => l.id === lessonId);
    const status = getAvailabilityStatus(lesson);
    
    if (status.status === 'full' && !selectedLessons.includes(lessonId)) {
      return;
    }
    
    setSelectedLessons(prev => {
      if (prev.includes(lessonId)) {
        return prev.filter(id => id !== lessonId);
      }
      if (prev.length < requiredLessons) {
        return [...prev, lessonId];
      }
      return prev;
    });
  };

  // Handle assessment selection
  const toggleAssessment = (assessmentId) => {
    const assessment = assessments.find(a => a.id === assessmentId);
    const status = getAvailabilityStatus(assessment);
    
    if (status.status === 'full' && !selectedAssessments.includes(assessmentId)) {
      return;
    }
    
    setSelectedAssessments(prev => {
      if (prev.includes(assessmentId)) {
        return prev.filter(id => id !== assessmentId);
      }
      if (prev.length < requiredAssessments) {
        return [...prev, assessmentId];
      }
      return prev;
    });
  };

  // Notify parent component
  useEffect(() => {
    onSelectionChange?.({
      lessons: selectedLessons,
      assessments: selectedAssessments,
      isValid: selectedLessons.length === requiredLessons && 
               selectedAssessments.length === requiredAssessments
    });
  }, [selectedLessons, selectedAssessments, requiredLessons, requiredAssessments, onSelectionChange]);

  const isSelectionValid = 
    selectedLessons.length === requiredLessons && 
    selectedAssessments.length === requiredAssessments;

  return (
    <div className="space-y-4">
      {/* Header with Selection Counter */}
      <div className="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-5 border border-blue-100">
        <div className="flex items-center justify-between flex-wrap gap-4">
          <div>
            <h3 className="text-lg font-semibold text-gray-900">
              Select Your Time Slots
            </h3>
            <p className="text-sm text-gray-600 mt-1">
              Choose the sessions that work best for you
            </p>
          </div>
          
          {/* Selection Counter */}
          <div className="flex gap-3">
            {requiredLessons > 0 && (
              <div className={`flex items-center px-4 py-2 rounded-full text-sm font-medium ${
                selectedLessons.length === requiredLessons 
                  ? 'bg-green-100 text-green-800' 
                  : 'bg-white text-gray-700 border border-gray-200'
              }`}>
                <AcademicCapIcon className="w-4 h-4 mr-2" />
                {selectedLessons.length} / {requiredLessons} sessions
                {selectedLessons.length === requiredLessons && (
                  <CheckCircleSolid className="w-4 h-4 ml-2 text-green-600" />
                )}
              </div>
            )}
            {requiredAssessments > 0 && (
              <div className={`flex items-center px-4 py-2 rounded-full text-sm font-medium ${
                selectedAssessments.length === requiredAssessments 
                  ? 'bg-green-100 text-green-800' 
                  : 'bg-white text-gray-700 border border-gray-200'
              }`}>
                <DocumentTextIcon className="w-4 h-4 mr-2" />
                {selectedAssessments.length} / {requiredAssessments} assessments
                {selectedAssessments.length === requiredAssessments && (
                  <CheckCircleSolid className="w-4 h-4 ml-2 text-green-600" />
                )}
              </div>
            )}
          </div>
        </div>

        {/* Completion Status */}
        {isSelectionValid && (
          <div className="mt-4 flex items-center text-green-700 bg-green-50 rounded-lg px-4 py-2 border border-green-200">
            <CheckCircleSolid className="w-5 h-5 mr-2" />
            <span className="text-sm font-medium">Selection complete! You can add to cart.</span>
          </div>
        )}
      </div>

      {/* Search (only if many items) */}
      {showSearch && (
        <div className="relative">
          <MagnifyingGlassIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
          <input
            type="text"
            placeholder="Search sessions..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
            className="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          />
        </div>
      )}

      {/* Main Content */}
      <div className="flex gap-6">
        {/* Sessions List */}
        <div className="flex-1 space-y-5">
          {/* Live Sessions */}
          {requiredLessons > 0 && (
            <div>
              <h4 className="text-base font-semibold text-gray-900 mb-3 flex items-center">
                <AcademicCapIcon className="w-5 h-5 text-blue-600 mr-2" />
                Sessions
              </h4>
              
              <div className="space-y-2">
                {filteredLessons.map(lesson => (
                  <SessionRow
                    key={lesson.id}
                    item={lesson}
                    type="lesson"
                    isSelected={selectedLessons.includes(lesson.id)}
                    onToggle={() => toggleLesson(lesson.id)}
                    isDisabled={
                      !selectedLessons.includes(lesson.id) && 
                      selectedLessons.length >= requiredLessons
                    }
                  />
                ))}
              </div>
              
              {filteredLessons.length === 0 && (
                <div className="text-center py-8 bg-gray-50 rounded-lg">
                  <p className="text-gray-500">No sessions available</p>
                  {selectedDate && (
                    <button
                      onClick={() => setSelectedDate(null)}
                      className="text-blue-600 text-sm mt-2 hover:underline"
                    >
                      Clear date filter
                    </button>
                  )}
                </div>
              )}
            </div>
          )}

          {/* Assessments */}
          {requiredAssessments > 0 && (
            <div>
              <h4 className="text-base font-semibold text-gray-900 mb-3 flex items-center">
                <DocumentTextIcon className="w-5 h-5 text-indigo-600 mr-2" />
                Assessments
              </h4>
              
              <div className="space-y-2">
                {filteredAssessments.map(assessment => (
                  <SessionRow
                    key={assessment.id}
                    item={assessment}
                    type="assessment"
                    isSelected={selectedAssessments.includes(assessment.id)}
                    onToggle={() => toggleAssessment(assessment.id)}
                    isDisabled={
                      !selectedAssessments.includes(assessment.id) && 
                      selectedAssessments.length >= requiredAssessments
                    }
                  />
                ))}
              </div>
              
              {filteredAssessments.length === 0 && (
                <div className="text-center py-8 bg-gray-50 rounded-lg">
                  <p className="text-gray-500">No assessments available</p>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Calendar Sidebar */}
        {requiredLessons > 0 && (
          <div className="w-64 flex-shrink-0 hidden lg:block">
            <MiniCalendar
              lessons={lessons}
              selectedDate={selectedDate}
              onDateSelect={setSelectedDate}
            />
          </div>
        )}
      </div>
    </div>
  );
}

/**
 * Simple, clean session row component
 */
function SessionRow({ item, type, isSelected, onToggle, isDisabled }) {
  const status = getAvailabilityStatus(item);
  const isFull = status.status === 'full';
  const isLesson = type === 'lesson';
  
  return (
    <div 
      onClick={() => !isDisabled && !isFull && onToggle()}
      className={`
        flex items-center p-4 rounded-xl border-2 transition-all cursor-pointer
        ${isSelected 
          ? 'border-blue-500 bg-blue-50' 
          : isFull
            ? 'border-gray-200 bg-gray-50 opacity-60 cursor-not-allowed'
            : isDisabled
              ? 'border-gray-200 bg-white opacity-50 cursor-not-allowed'
              : 'border-gray-200 bg-white hover:border-blue-300 hover:shadow-sm'
        }
      `}
    >
      {/* Checkbox */}
      <div className={`
        flex-shrink-0 w-6 h-6 rounded-full mr-4 flex items-center justify-center
        ${isSelected 
          ? 'bg-blue-600' 
          : 'border-2 border-gray-300'
        }
      `}>
        {isSelected && <CheckCircleSolid className="w-6 h-6 text-white" />}
      </div>
      
      {/* Content */}
      <div className="flex-1 min-w-0">
        <h5 className="font-medium text-gray-900">{item.title}</h5>
        
        <div className="flex items-center mt-1 text-sm text-gray-500 space-x-4">
          {isLesson && item.start_time && (() => {
            const range = formatDateTimeRange(item.start_time, item.end_time);
            return (
              <span className="flex items-center">
                <CalendarDaysIcon className="w-4 h-4 mr-1" />
                {range.date}
                <span className="mx-1">•</span>
                <ClockIcon className="w-4 h-4 mr-1" />
                {formatTime(item.start_time)}
                {item.end_time && (
                  <span className="text-gray-400">
                    {' - '}
                    {!range.isSameDay && <>{range.endDate} </>}
                    {formatTime(item.end_time)}
                  </span>
                )}
              </span>
            );
          })()}
          
          {isLesson && item.lesson_mode && (
            <span className="flex items-center">
              {item.lesson_mode === 'online' ? (
                <>
                  <VideoCameraIcon className="w-4 h-4 mr-1 text-blue-500" />
                  <span className="text-blue-600">Online</span>
                </>
              ) : (
                <>
                  <MapPinIcon className="w-4 h-4 mr-1 text-green-500" />
                  <span className="text-green-600">In-person</span>
                </>
              )}
            </span>
          )}
          
          {!isLesson && item.deadline && (
            <span className="flex items-center">
              <ClockIcon className="w-4 h-4 mr-1" />
              Due: {new Date(item.deadline).toLocaleDateString()}
            </span>
          )}
        </div>
      </div>
      
      {/* Availability Badge */}
      <div className="flex-shrink-0 ml-4">
        <span className={`
          inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
          ${status.bgColor} ${status.textColor}
        `}>
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
      </div>
    </div>
  );
}
