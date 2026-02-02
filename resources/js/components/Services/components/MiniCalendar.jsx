import React, { useState, useMemo } from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/24/outline';
import { getDatesWithSessions } from '../utils/timeSlotHelpers';

/**
 * MiniCalendar Component
 * Shows a compact calendar with session availability indicators
 */
export default function MiniCalendar({ 
  lessons, 
  selectedDate, 
  onDateSelect,
  className = '' 
}) {
  const [currentMonth, setCurrentMonth] = useState(new Date());
  
  // Get dates that have sessions
  const datesWithSessions = useMemo(() => {
    return getDatesWithSessions(lessons, 'start_time');
  }, [lessons]);
  
  // Get calendar days for current month view
  const calendarDays = useMemo(() => {
    const year = currentMonth.getFullYear();
    const month = currentMonth.getMonth();
    
    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    
    const startDay = firstDayOfMonth.getDay();
    const daysInMonth = lastDayOfMonth.getDate();
    
    const days = [];
    
    // Add empty days for alignment (Monday start)
    const adjustedStartDay = startDay === 0 ? 6 : startDay - 1;
    for (let i = 0; i < adjustedStartDay; i++) {
      days.push({ day: null, date: null });
    }
    
    // Add month days
    for (let day = 1; day <= daysInMonth; day++) {
      const date = new Date(year, month, day);
      const dateKey = date.toDateString();
      const sessionInfo = datesWithSessions[dateKey];
      
      days.push({
        day,
        date,
        dateKey,
        hasSessions: !!sessionInfo,
        sessionCount: sessionInfo?.count || 0,
        availableCount: sessionInfo?.available || 0,
        isToday: new Date().toDateString() === dateKey,
        isSelected: selectedDate?.toDateString() === dateKey,
        isPast: date < new Date(new Date().setHours(0, 0, 0, 0))
      });
    }
    
    return days;
  }, [currentMonth, datesWithSessions, selectedDate]);
  
  const navigateMonth = (direction) => {
    setCurrentMonth(prev => {
      const newDate = new Date(prev);
      newDate.setMonth(prev.getMonth() + direction);
      return newDate;
    });
  };
  
  const monthYearLabel = currentMonth.toLocaleDateString('en-GB', {
    month: 'long',
    year: 'numeric'
  });
  
  const weekDays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  
  return (
    <div className={`bg-white rounded-lg border border-gray-200 p-4 ${className}`}>
      {/* Header */}
      <div className="flex items-center justify-between mb-4">
        <h3 className="text-sm font-semibold text-gray-900">{monthYearLabel}</h3>
        <div className="flex items-center space-x-1">
          <button
            type="button"
            onClick={() => navigateMonth(-1)}
            className="p-1 rounded hover:bg-gray-100 transition-colors"
          >
            <ChevronLeftIcon className="w-4 h-4 text-gray-600" />
          </button>
          <button
            type="button"
            onClick={() => navigateMonth(1)}
            className="p-1 rounded hover:bg-gray-100 transition-colors"
          >
            <ChevronRightIcon className="w-4 h-4 text-gray-600" />
          </button>
        </div>
      </div>
      
      {/* Week day headers */}
      <div className="grid grid-cols-7 gap-1 mb-2">
        {weekDays.map(day => (
          <div key={day} className="text-center text-xs font-medium text-gray-500 py-1">
            {day}
          </div>
        ))}
      </div>
      
      {/* Calendar grid */}
      <div className="grid grid-cols-7 gap-1">
        {calendarDays.map((dayInfo, index) => {
          if (!dayInfo.day) {
            return <div key={`empty-${index}`} className="p-1" />;
          }
          
          const isClickable = dayInfo.hasSessions && !dayInfo.isPast;
          
          return (
            <button
              key={dayInfo.dateKey}
              type="button"
              onClick={() => isClickable && onDateSelect(dayInfo.date)}
              disabled={!isClickable}
              className={`
                relative p-1 rounded-md text-xs font-medium transition-all
                ${dayInfo.isSelected 
                  ? 'bg-blue-600 text-white' 
                  : dayInfo.isToday
                    ? 'bg-blue-50 text-blue-700 ring-1 ring-blue-200'
                    : dayInfo.hasSessions && !dayInfo.isPast
                      ? 'bg-green-50 text-green-800 hover:bg-green-100 cursor-pointer'
                      : dayInfo.isPast
                        ? 'text-gray-300'
                        : 'text-gray-700 hover:bg-gray-50'
                }
                ${!isClickable && !dayInfo.isSelected ? 'cursor-default' : ''}
              `}
            >
              <span className="block">{dayInfo.day}</span>
              
              {/* Session indicator */}
              {dayInfo.hasSessions && (
                <span className={`
                  absolute bottom-0.5 left-1/2 transform -translate-x-1/2
                  text-[9px] font-bold
                  ${dayInfo.isSelected ? 'text-blue-200' : 'text-green-600'}
                `}>
                  {dayInfo.sessionCount}
                </span>
              )}
              
              {/* Dot indicator for sessions */}
              {dayInfo.hasSessions && !dayInfo.isSelected && (
                <span className={`
                  absolute top-0.5 right-0.5 w-1.5 h-1.5 rounded-full
                  ${dayInfo.availableCount > 0 ? 'bg-green-500' : 'bg-red-400'}
                `} />
              )}
            </button>
          );
        })}
      </div>
      
      {/* Legend */}
      <div className="mt-3 pt-3 border-t border-gray-100 flex items-center justify-center space-x-4 text-xs text-gray-500">
        <div className="flex items-center">
          <span className="w-2 h-2 rounded-full bg-green-500 mr-1" />
          <span>Available</span>
        </div>
        <div className="flex items-center">
          <span className="w-2 h-2 rounded-full bg-red-400 mr-1" />
          <span>Full</span>
        </div>
      </div>
      
      {/* Clear selection button */}
      {selectedDate && (
        <button
          type="button"
          onClick={() => onDateSelect(null)}
          className="mt-2 w-full text-xs text-blue-600 hover:text-blue-800 py-1"
        >
          Clear date filter
        </button>
      )}
    </div>
  );
}
